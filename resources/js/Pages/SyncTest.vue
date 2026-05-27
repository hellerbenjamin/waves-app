<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import Slider from 'primevue/slider';
import ProgressBar from 'primevue/progressbar';
import SelectButton from 'primevue/selectbutton';
import Message from 'primevue/message';
import Card from 'primevue/card';
import { splitChannelsToMonoWavs } from '@/lib/wavChannelSplit.js';

/**
 * Sync test rig: pick a multi-channel WAV, extract each channel as a mono WAV
 * Blob, load them into N parallel `<audio>` elements, and measure how far
 * apart their currentTime values drift while playing. The rig optionally
 * resyncs stragglers via a hard `currentTime` snap or a smooth `playbackRate`
 * nudge. Numbers in the table tell us whether N-stream playback is viable for
 * the multi-channel mixer rebuild we've been discussing — read the README
 * section on the in-browser splitting flow for context.
 */

const fileInput = ref(null);
const phase = ref('idle'); // 'idle' | 'extracting' | 'ready' | 'playing'
const extractProgress = ref(0);
const errorMessage = ref('');
const sourceHeader = ref(null);
const channelBlobs = ref([]);   // Blob[] — one mono WAV per channel
const channelUrls = ref([]);    // Object URLs
const maxSeconds = ref(300);    // clip per-channel extraction (default 5 min)

// Sync controls
const watchdogMode = ref('off'); // 'off' | 'snap' | 'rate'
const thresholdMs = ref(30);     // snap if |drift| > this
const watchdogIntervalMs = ref(200);
const watchdogOptions = [
    { label: 'Off', value: 'off' },
    { label: 'Snap', value: 'snap' },
    { label: 'Rate', value: 'rate' },
];

// Live measurements, one row per channel.
const channelStats = ref([]); // [{ currentTime, drift, maxAbsDrift, snaps }]

let audioEls = [];
let watchdogTimer = null;
let measureTimer = null;

const pickFile = () => fileInput.value?.click();

const onFileSelected = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    teardown();
    phase.value = 'extracting';
    extractProgress.value = 0;
    errorMessage.value = '';

    try {
        const { header, channels } = await splitChannelsToMonoWavs(file, {
            maxSeconds: maxSeconds.value,
            onProgress: (p) => { extractProgress.value = p; },
        });
        sourceHeader.value = header;
        channelBlobs.value = channels;
        channelUrls.value = channels.map((b) => URL.createObjectURL(b));
        prepareAudioElements();
        phase.value = 'ready';
    } catch (e) {
        phase.value = 'idle';
        errorMessage.value = e?.message || String(e);
    }
};

const prepareAudioElements = () => {
    audioEls.forEach((el) => { try { el.pause(); el.src = ''; } catch {} });
    audioEls = channelUrls.value.map((url) => {
        const el = new Audio(url);
        el.preload = 'auto';
        return el;
    });
    channelStats.value = audioEls.map(() => ({
        currentTime: 0, drift: 0, maxAbsDrift: 0, snaps: 0,
    }));
};

const playAll = async () => {
    if (!audioEls.length) return;
    // Reset to t=0 across all elements before starting so we start aligned.
    for (const el of audioEls) {
        try { el.pause(); el.currentTime = 0; el.playbackRate = 1; } catch {}
    }
    // Wait for all to be ready, then kick play() in the same task tick so
    // they begin as close to simultaneously as the browser allows.
    await Promise.all(audioEls.map((el) => new Promise((resolve) => {
        if (el.readyState >= 2) return resolve();
        const onCanPlay = () => { el.removeEventListener('canplay', onCanPlay); resolve(); };
        el.addEventListener('canplay', onCanPlay);
    })));
    await Promise.all(audioEls.map((el) => el.play().catch(() => {})));
    phase.value = 'playing';
    startWatchdog();
    startMeasurement();
};

const pauseAll = () => {
    for (const el of audioEls) { try { el.pause(); } catch {} }
    phase.value = 'ready';
    stopWatchdog();
    stopMeasurement();
};

const stopAll = () => {
    for (const el of audioEls) {
        try { el.pause(); el.currentTime = 0; el.playbackRate = 1; } catch {}
    }
    phase.value = 'ready';
    stopWatchdog();
    stopMeasurement();
};

const resetStats = () => {
    channelStats.value = channelStats.value.map((s) => ({
        ...s, drift: 0, maxAbsDrift: 0, snaps: 0,
    }));
};

const startWatchdog = () => {
    stopWatchdog();
    if (watchdogMode.value === 'off') return;
    watchdogTimer = setInterval(applyWatchdog, watchdogIntervalMs.value);
};
const stopWatchdog = () => {
    if (watchdogTimer != null) clearInterval(watchdogTimer);
    watchdogTimer = null;
    // Make sure no element is left with a non-1.0 playbackRate.
    for (const el of audioEls) { try { el.playbackRate = 1; } catch {} }
};

const startMeasurement = () => {
    stopMeasurement();
    // Faster cadence than the watchdog so the displayed numbers feel live.
    measureTimer = setInterval(measureDrift, 100);
};
const stopMeasurement = () => {
    if (measureTimer != null) clearInterval(measureTimer);
    measureTimer = null;
};

// Reference channel is index 0. Drift for channel i = i.currentTime - ref.currentTime.
const measureDrift = () => {
    if (!audioEls.length) return;
    const ref = audioEls[0].currentTime;
    for (let i = 0; i < audioEls.length; i++) {
        const t = audioEls[i].currentTime;
        const drift = t - ref;
        const s = channelStats.value[i];
        s.currentTime = t;
        s.drift = drift;
        const abs = Math.abs(drift);
        if (abs > s.maxAbsDrift) s.maxAbsDrift = abs;
    }
};

const applyWatchdog = () => {
    if (!audioEls.length) return;
    const ref = audioEls[0].currentTime;
    const thresholdSec = thresholdMs.value / 1000;

    for (let i = 1; i < audioEls.length; i++) {
        const el = audioEls[i];
        const drift = el.currentTime - ref;
        if (Math.abs(drift) < thresholdSec) {
            if (watchdogMode.value === 'rate' && el.playbackRate !== 1) el.playbackRate = 1;
            continue;
        }

        if (watchdogMode.value === 'snap') {
            try { el.currentTime = ref; channelStats.value[i].snaps++; } catch {}
        } else if (watchdogMode.value === 'rate') {
            // Bring the laggard (or speeder) back over ~1 s by tweaking
            // playbackRate. Clamp so we don't get audible pitch shift.
            const target = 1 - drift; // negative drift => speed up
            el.playbackRate = Math.max(0.95, Math.min(1.05, target));
        }
    }
};

const teardown = () => {
    stopAll();
    audioEls.forEach((el) => { try { el.src = ''; } catch {} });
    audioEls = [];
    channelUrls.value.forEach((u) => URL.revokeObjectURL(u));
    channelUrls.value = [];
    channelBlobs.value = [];
    channelStats.value = [];
    sourceHeader.value = null;
};

onBeforeUnmount(teardown);

const summary = computed(() => {
    if (!channelStats.value.length) return null;
    const maxAbsDrift = Math.max(...channelStats.value.map((s) => s.maxAbsDrift));
    const totalSnaps = channelStats.value.reduce((sum, s) => sum + s.snaps, 0);
    return {
        maxAbsDriftMs: (maxAbsDrift * 1000).toFixed(1),
        totalSnaps,
    };
});

const formatHz = (n) => `${(n / 1000).toFixed(1)} kHz`;
</script>

<template>
    <Head title="Sync test" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="page-title">Sync test</h2>
        </template>

        <div class="stack">
            <Message severity="info" :closable="false">
                Pick a multi-channel WAV. Each channel is extracted as a mono WAV Blob (clipped to the test duration) and
                played through its own &lt;audio&gt; element. The table shows per-channel drift relative to channel 1 — if
                it stays under ~30 ms over a multi-minute run, the per-channel-as-separate-file mixer is viable.
            </Message>

            <Card>
                <template #content>
                    <div class="controls">
                        <div class="control">
                            <label>Source file</label>
                            <Button icon="pi pi-upload" label="Pick WAV" @click="pickFile" :disabled="phase === 'extracting'" />
                            <input ref="fileInput" type="file" accept=".wav,audio/wav" style="display:none" @change="onFileSelected" />
                        </div>
                        <div class="control">
                            <label>Test duration (s)</label>
                            <InputNumber v-model="maxSeconds" :min="10" :max="3600" :step="30" showButtons />
                        </div>
                        <div v-if="sourceHeader" class="control">
                            <label>Source</label>
                            <span class="readout">
                                {{ sourceHeader.channels }}ch · {{ formatHz(sourceHeader.sampleRate) }} ·
                                {{ sourceHeader.bitsPerSample }}-bit · {{ Math.round(sourceHeader.durationSeconds) }}s
                            </span>
                        </div>
                    </div>

                    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

                    <div v-if="phase === 'extracting'" class="extract-progress">
                        <p>Extracting channels…</p>
                        <ProgressBar :value="Math.round(extractProgress * 100)" />
                    </div>
                </template>
            </Card>

            <Card v-if="phase === 'ready' || phase === 'playing'">
                <template #content>
                    <div class="controls">
                        <div class="control">
                            <label>Playback</label>
                            <div class="btn-row">
                                <Button icon="pi pi-play" label="Play all" :disabled="phase === 'playing'" @click="playAll" />
                                <Button icon="pi pi-pause" label="Pause" :disabled="phase !== 'playing'" @click="pauseAll" />
                                <Button icon="pi pi-stop" label="Stop" severity="secondary" @click="stopAll" />
                                <Button icon="pi pi-refresh" label="Reset stats" severity="secondary" text @click="resetStats" />
                            </div>
                        </div>
                        <div class="control">
                            <label>Watchdog mode</label>
                            <SelectButton v-model="watchdogMode" :options="watchdogOptions" optionLabel="label" optionValue="value" @update:modelValue="startWatchdog" />
                        </div>
                        <div class="control control-wide">
                            <label>Resync threshold <span class="muted">({{ thresholdMs }} ms)</span></label>
                            <Slider v-model="thresholdMs" :min="5" :max="200" :step="1" />
                        </div>
                    </div>

                    <div v-if="summary" class="summary">
                        Max abs drift since reset: <strong>{{ summary.maxAbsDriftMs }} ms</strong>
                        · Total snaps: <strong>{{ summary.totalSnaps }}</strong>
                    </div>

                    <table class="drift-table">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th>currentTime</th>
                                <th>Drift vs ch1 (ms)</th>
                                <th>Max abs drift (ms)</th>
                                <th>Snaps</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(s, i) in channelStats" :key="i" :class="{ ref: i === 0 }">
                                <td>{{ i + 1 }}<span v-if="i === 0" class="muted"> (ref)</span></td>
                                <td>{{ s.currentTime.toFixed(3) }}</td>
                                <td :class="{ warn: Math.abs(s.drift) * 1000 > thresholdMs }">
                                    {{ i === 0 ? '—' : (s.drift * 1000).toFixed(1) }}
                                </td>
                                <td>{{ (s.maxAbsDrift * 1000).toFixed(1) }}</td>
                                <td>{{ s.snaps }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.stack { display: flex; flex-direction: column; gap: 1.25rem; }

.controls {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 1.25rem;
    margin-bottom: 0.75rem;
}
.control { display: flex; flex-direction: column; gap: 0.5rem; }
.control-wide { grid-column: span 2; min-width: 18rem; }
.control label { font-size: 0.875rem; font-weight: 500; }
.btn-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.readout { font-size: 0.875rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.muted { color: var(--p-text-muted-color); font-weight: 400; }

.extract-progress { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem; }
.extract-progress p { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }

.summary { font-size: 0.9375rem; margin: 0.5rem 0 0.75rem; }
.drift-table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; font-size: 0.875rem; }
.drift-table th, .drift-table td {
    text-align: right; padding: 0.375rem 0.75rem; border-bottom: 1px solid var(--p-content-border-color);
}
.drift-table th:first-child, .drift-table td:first-child { text-align: left; }
.drift-table tr.ref { background: var(--p-surface-100); }
.drift-table td.warn { color: var(--p-red-500); font-weight: 600; }
</style>
