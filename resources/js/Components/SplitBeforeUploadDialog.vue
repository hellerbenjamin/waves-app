<script setup>
import { ref, watch, onBeforeUnmount, nextTick, shallowRef, computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Slider from 'primevue/slider';
import InputText from 'primevue/inputtext';
import ProgressBar from 'primevue/progressbar';
import Message from 'primevue/message';
import WaveSurfer from 'wavesurfer.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';
import { readWavHeader, buildSegmentBlob } from '@/lib/wav.js';
import { scanSilences, invertToRegions } from '@/lib/wavSilence.js';

/**
 * Split a long WAV File into per-region Blobs entirely in the browser, then
 * hand the parent the segments to upload via its normal pipeline. The source
 * file is never uploaded — bandwidth and R2 storage scale with the kept
 * segments, not the original recording.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    file: { type: Object, default: null }, // File
});

const emit = defineEmits([
    'update:visible',
    'commit',       // (segments: {name, blob}[])
    'upload-whole', // upload the source unmodified
    'cancel',       // drop the file
]);

const waveformEl = ref(null);

// State machine for the dialog's body. The header parses cheaply, so we hop
// straight from `idle` to `scanning` on open; an unparseable WAV falls into
// `error` and the parent decides whether to fall back to a vanilla upload.
const phase = ref('idle'); // 'idle' | 'scanning' | 'ready' | 'committing' | 'error'
const scanProgress = ref(0);
const errorMessage = ref('');

const header = shallowRef(null);
const peaks = ref([]);
const regions = ref([]); // [{ id, start, end, name }]
const baseName = ref('');

// Detection sliders. Defaults mirror the server-side DetectSongs job so the
// "feel" stays the same across the two paths.
const silenceDb = ref(-40);
const minSilence = ref(1.5);
const minRegion = ref(30);

let ws = null;
let regionsPlugin = null;
let regionDragUnsub = null;
let suppressRegionEvents = false;
let scanToken = 0; // cancellation token for in-flight scans

const formatTime = (s) => {
    if (!isFinite(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
};

const cssVar = (name, fallback) => {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
};

const regionColor = (i) => [
    'rgba(99, 102, 241, 0.20)',
    'rgba(16, 185, 129, 0.20)',
    'rgba(244, 114, 182, 0.20)',
    'rgba(245, 158, 11, 0.20)',
][i % 4];

// Open: parse the header, then kick off the first scan. The header gives us
// an authoritative duration we render against while the scan is still running.
watch(() => props.visible, async (open) => {
    if (!open) {
        teardown();
        return;
    }
    if (!props.file) return;

    phase.value = 'scanning';
    scanProgress.value = 0;
    errorMessage.value = '';
    baseName.value = (props.file.name || 'Track').replace(/\.[^.]+$/, '') || 'Track';

    try {
        header.value = await readWavHeader(props.file);
    } catch (e) {
        phase.value = 'error';
        errorMessage.value = e?.message || String(e);
        return;
    }

    await runScan();
}, { immediate: false });

const runScan = async () => {
    if (!header.value || !props.file) return;
    const myToken = ++scanToken;

    phase.value = 'scanning';
    scanProgress.value = 0;

    try {
        const result = await scanSilences(props.file, header.value, {
            silenceDb: silenceDb.value,
            minSilence: minSilence.value,
            windowMs: 20,
            peakStrides: 2000,
            onProgress: (p) => {
                if (myToken === scanToken) scanProgress.value = p;
            },
        });

        // A later re-scan superseded us while we were running; drop the result.
        if (myToken !== scanToken) return;

        peaks.value = result.peaks;
        const detected = invertToRegions(result.silences, header.value.durationSeconds, minRegion.value);

        // Suggested names mirror the server-side detect path.
        regions.value = detected.map((r, i) => ({
            id: 'r' + (i + 1),
            start: Number(r.start.toFixed(3)),
            end: Number(r.end.toFixed(3)),
            name: `${baseName.value} - Part ${i + 1}`,
        }));

        phase.value = 'ready';
        await nextTick();
        renderWaveform();
    } catch (e) {
        if (myToken !== scanToken) return;
        phase.value = 'error';
        errorMessage.value = e?.message || String(e);
    }
};

const renderWaveform = () => {
    if (!waveformEl.value || !peaks.value.length || !header.value) return;

    // Fresh instance per scan: peaks change on every re-detect and wavesurfer's
    // peaks reload story is fiddly. Destroying and recreating is cheap here.
    ws?.destroy();
    regionsPlugin = RegionsPlugin.create();
    ws = WaveSurfer.create({
        container: waveformEl.value,
        peaks: [peaks.value],
        duration: header.value.durationSeconds,
        // No media — this is a non-playable preview surface for region editing.
        interact: true,
        height: 96,
        waveColor: cssVar('--p-primary-200', '#c7d2fe'),
        progressColor: cssVar('--p-primary-color', '#6366f1'),
        cursorColor: 'transparent',
        barWidth: 2,
        barGap: 1,
        barRadius: 2,
        normalize: false,
        plugins: [regionsPlugin],
    });

    bindRegionEvents();
    syncRegionsToWaveform();
    // Drag on empty waveform space to add a new region.
    regionDragUnsub?.();
    regionDragUnsub = regionsPlugin.enableDragSelection({ color: 'rgba(99, 102, 241, 0.15)' });
};

const bindRegionEvents = () => {
    regionsPlugin.on('region-updated', (region) => {
        if (suppressRegionEvents) return;
        const target = regions.value.find((r) => r.id === region.id);
        if (!target) return;
        target.start = Number(region.start.toFixed(3));
        target.end = Number(region.end.toFixed(3));
    });

    regionsPlugin.on('region-created', (region) => {
        if (suppressRegionEvents) return;
        if (regions.value.some((r) => r.id === region.id)) return;
        const id = 'r' + Date.now();
        const name = `${baseName.value} - Part ${regions.value.length + 1}`;
        region.setOptions({ id, content: name, color: regionColor(regions.value.length) });
        regions.value.push({
            id,
            start: Number(region.start.toFixed(3)),
            end: Number(region.end.toFixed(3)),
            name,
        });
    });
};

const syncRegionsToWaveform = () => {
    if (!regionsPlugin) return;
    suppressRegionEvents = true;
    try {
        regionsPlugin.clearRegions();
        regions.value.forEach((r, i) => {
            regionsPlugin.addRegion({
                id: r.id,
                start: r.start,
                end: r.end,
                content: r.name,
                color: regionColor(i),
                drag: true,
                resize: true,
            });
        });
    } finally {
        suppressRegionEvents = false;
    }
};

const removeRegion = (id) => {
    regions.value = regions.value.filter((r) => r.id !== id);
    syncRegionsToWaveform();
};

const renameRegion = (id, name) => {
    const r = regions.value.find((x) => x.id === id);
    if (!r) return;
    r.name = name;
    regionsPlugin?.getRegions().find((rr) => rr.id === id)?.setOptions({ content: name });
};

const teardown = () => {
    scanToken++; // invalidate any in-flight scan
    regionDragUnsub?.();
    regionDragUnsub = null;
    ws?.destroy();
    ws = null;
    regionsPlugin = null;
    regions.value = [];
    peaks.value = [];
    header.value = null;
    phase.value = 'idle';
};

onBeforeUnmount(teardown);

// Track which terminal action the user took so a dialog close from ESC or
// outside-click can be reported as a cancel — without an explicit click
// emitting a stray cancel on top of a commit.
let decided = false;

const close = () => emit('update:visible', false);

const onCancel = () => {
    if (decided) return;
    decided = true;
    emit('cancel');
    close();
};

const onUploadWhole = () => {
    if (decided) return;
    decided = true;
    emit('upload-whole');
    close();
};

const onCommit = async () => {
    if (decided || !header.value || !regions.value.length) return;
    decided = true;
    phase.value = 'committing';

    // Build the segments synchronously — buildSegmentBlob is zero-copy
    // (header + File.slice). Names get a `.wav` suffix iff they don't already.
    const segments = regions.value.map((r) => ({
        name: /\.wav$/i.test(r.name) ? r.name : `${r.name}.wav`,
        blob: buildSegmentBlob(props.file, header.value, { start: r.start, end: r.end }),
    }));

    emit('commit', segments);
    close();
};

// Reset the decision flag whenever the dialog reopens for a fresh file.
watch(() => props.visible, (v) => {
    if (v) decided = false;
});

const dialogVisible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});
</script>

<template>
    <Dialog
        v-model:visible="dialogVisible"
        modal
        :closable="phase !== 'committing'"
        :style="{ width: 'min(90vw, 60rem)' }"
        :header="`Split ${props.file?.name ?? 'recording'} before upload`"
        @hide="onCancel"
    >
        <div class="split-dialog">
            <p class="split-intro">
                This recording is long enough that we'll split it into songs before uploading — the source file stays on
                your computer, only the kept segments are sent. Drag region edges or add new ones, then commit to upload.
            </p>

            <Message v-if="phase === 'error'" severity="error" :closable="false">
                {{ errorMessage || 'Could not parse this WAV file.' }}
            </Message>

            <div v-if="phase === 'scanning'" class="scan-progress">
                <p>Analysing audio…</p>
                <ProgressBar :value="Math.round(scanProgress * 100)" />
            </div>

            <template v-if="phase === 'ready' || phase === 'committing'">
                <div ref="waveformEl" class="dialog-waveform" />

                <div class="split-params">
                    <div class="split-param">
                        <label>Silence threshold <span class="split-param-val">{{ silenceDb }} dB</span></label>
                        <Slider v-model="silenceDb" :min="-80" :max="-10" :step="1" @change="runScan" />
                    </div>
                    <div class="split-param">
                        <label>Min silence length <span class="split-param-val">{{ minSilence }} s</span></label>
                        <Slider v-model="minSilence" :min="0.2" :max="10" :step="0.1" @change="runScan" />
                    </div>
                    <div class="split-param">
                        <label>Min song length <span class="split-param-val">{{ minRegion }} s</span></label>
                        <Slider v-model="minRegion" :min="1" :max="600" :step="1" @change="runScan" />
                    </div>
                </div>

                <div v-if="regions.length" class="region-list">
                    <div v-for="(r, i) in regions" :key="r.id" class="region-row">
                        <span class="region-index">{{ i + 1 }}</span>
                        <InputText
                            :model-value="r.name"
                            class="region-name"
                            @update:model-value="(v) => renameRegion(r.id, v)"
                        />
                        <span class="region-times">
                            {{ formatTime(r.start) }} – {{ formatTime(r.end) }}
                            <span class="region-len">({{ formatTime(r.end - r.start) }})</span>
                        </span>
                        <Button
                            icon="pi pi-trash"
                            text
                            rounded
                            severity="danger"
                            size="small"
                            aria-label="Remove region"
                            @click="removeRegion(r.id)"
                        />
                    </div>
                </div>
                <p v-else class="region-empty">
                    No regions long enough were found. Lower the minimum song length or the silence threshold.
                </p>
            </template>
        </div>

        <template #footer>
            <Button label="Cancel" text severity="secondary" :disabled="phase === 'committing'" @click="onCancel" />
            <Button label="Upload as one track" icon="pi pi-upload" outlined :disabled="phase === 'committing' || phase === 'scanning'" @click="onUploadWhole" />
            <Button
                label="Commit split"
                icon="pi pi-check"
                severity="success"
                :loading="phase === 'committing'"
                :disabled="phase !== 'ready' || !regions.length"
                @click="onCommit"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.split-dialog { display: flex; flex-direction: column; gap: 1rem; }
.split-intro { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
.dialog-waveform { width: 100%; }
.scan-progress { display: flex; flex-direction: column; gap: 0.5rem; }
.scan-progress p { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }

.split-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1.25rem; }
.split-param label { display: flex; justify-content: space-between; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; }
.split-param-val { color: var(--p-primary-color); font-variant-numeric: tabular-nums; }

.region-list { display: flex; flex-direction: column; gap: 0.5rem; }
.region-row {
    display: grid;
    grid-template-columns: 1.5rem 1fr auto auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.375rem 0;
    border-bottom: 1px solid var(--p-content-border-color);
}
.region-row:last-child { border-bottom: none; }
.region-index { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.region-name { width: 100%; }
.region-times { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; white-space: nowrap; }
.region-len { opacity: 0.7; margin-left: 0.25rem; }
.region-empty { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
</style>
