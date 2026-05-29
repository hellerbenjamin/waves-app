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
import { detectSilences, invertToRegions } from '@/lib/wavSilence.js';
import { PcmStreamPlayer } from '@/lib/pcmStreamPlayer.js';

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

// Local playback so the user can audition regions before labelling them.
// A streaming Web Audio player reads PCM windows straight from the source File
// on demand (no upload, no 4 GB cap), so even an RF64 recording auditions.
// wavesurfer runs media-less here: it draws the waveform from our scanned peaks
// and we drive its cursor from the player's clock.
const isPlaying = ref(false);
const currentTime = ref(0);
const activeRegionId = ref(null);

const silenceDb = ref(-40);
const minSilence = ref(1.5);
const minRegion = ref(30);

let ws = null;
let regionsPlugin = null;
let regionDragUnsub = null;
let suppressRegionEvents = false;

// Streaming PCM player; built once the header is parsed (see renderWaveform).
let player = null;

// Heavy scan runs once in a Web Worker; the result is cached so re-detection
// on every slider tick is a fast in-memory pass on the main thread.
let scanWorker = null;
let rmsCache = null; // Float32Array of per-window RMS magnitudes
let framesPerWindowCache = 0;

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

// Open: parse the header, then kick off the heavy scan in a Web Worker.
// The header gives us an authoritative duration we render against while the
// scan is still running; the worker keeps the main thread responsive so the
// progress bar, sliders, and ESC-to-cancel all stay live on multi-GB files.
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

    runScanInWorker();
}, { immediate: false });

const runScanInWorker = () => {
    // Tear down any prior worker so a re-open never reuses a stale one.
    scanWorker?.terminate();
    scanWorker = new Worker(new URL('@/lib/wavSilenceWorker.js', import.meta.url), { type: 'module' });

    scanWorker.onmessage = (event) => {
        const msg = event.data;
        if (msg.type === 'progress') {
            scanProgress.value = msg.progress;
        } else if (msg.type === 'done') {
            rmsCache = new Float32Array(msg.rmsBuffer);
            framesPerWindowCache = msg.framesPerWindow;
            peaks.value = msg.peaks;
            scanWorker?.terminate();
            scanWorker = null;
            applyDetection();
            phase.value = 'ready';
            nextTick(renderWaveform);
        } else if (msg.type === 'error') {
            phase.value = 'error';
            errorMessage.value = msg.message;
            scanWorker?.terminate();
            scanWorker = null;
        }
    };

    scanWorker.onerror = (err) => {
        phase.value = 'error';
        errorMessage.value = err?.message || 'Worker failed';
        scanWorker?.terminate();
        scanWorker = null;
    };

    scanWorker.postMessage({
        file: props.file,
        header: { ...header.value }, // strip reactivity for structured-clone
        opts: { windowMs: 20, peakStrides: 2000 },
    });
};

/**
 * Recompute regions from the cached RMS envelope. Called once after the scan
 * completes and again on every slider tick — runs in milliseconds because it
 * just walks the in-memory Float32Array.
 *
 * Preserves any region renames the user already made: when the count and
 * order line up, names carry over; otherwise we fall back to defaults so a
 * drastic threshold change doesn't strand stale names.
 */
const applyDetection = () => {
    if (!rmsCache || !header.value) return;

    const silences = detectSilences(
        rmsCache,
        { framesPerWindow: framesPerWindowCache, sampleRate: header.value.sampleRate },
        { silenceDb: silenceDb.value, minSilence: minSilence.value },
    );
    const detected = invertToRegions(silences, header.value.durationSeconds, minRegion.value);

    const prior = regions.value;
    regions.value = detected.map((r, i) => ({
        id: 'r' + (i + 1),
        start: Number(r.start.toFixed(3)),
        end: Number(r.end.toFixed(3)),
        name: prior[i]?.name || `${baseName.value} - Part ${i + 1}`,
    }));

    syncRegionsToWaveform();
};

const renderWaveform = () => {
    if (!waveformEl.value || !peaks.value.length || !header.value) return;

    // Fresh instance per scan: peaks change on every re-detect and wavesurfer's
    // peaks reload story is fiddly. Destroying and recreating is cheap here.
    ws?.destroy();
    regionsPlugin = RegionsPlugin.create();
    ws = WaveSurfer.create({
        container: waveformEl.value,
        // Media-less: peaks + duration render the waveform and seed getDuration()
        // immediately, so the cursor and regions position correctly with no
        // <audio> element. Audio comes from the streaming player below.
        peaks: [peaks.value],
        duration: header.value.durationSeconds,
        interact: true,
        height: 96,
        waveColor: cssVar('--p-primary-200', '#c7d2fe'),
        progressColor: cssVar('--p-primary-color', '#6366f1'),
        cursorColor: cssVar('--p-primary-color', '#6366f1'),
        barWidth: 2,
        barGap: 1,
        barRadius: 2,
        normalize: false,
        plugins: [regionsPlugin],
    });

    isPlaying.value = false;
    currentTime.value = 0;

    buildPlayer();

    ws.on('ready', () => { syncRegionsToWaveform(); });

    // Click-to-seek: wavesurfer moves its own cursor and reports the target
    // time; we point the streaming player at it (and exit region mode).
    ws.on('interaction', (time) => {
        activeRegionId.value = null;
        player?.seek(time);
    });

    bindRegionEvents();
    syncRegionsToWaveform();
    // Drag on empty waveform space to add a new region.
    regionDragUnsub?.();
    regionDragUnsub = regionsPlugin.enableDragSelection({ color: 'rgba(99, 102, 241, 0.15)' });
};

// Build the streaming player from the parsed header. The source File is a
// single-source stitched timeline as far as the player is concerned.
const buildPlayer = () => {
    const h = header.value;
    if (!h) return;
    player?.destroy();
    const descriptor = {
        sources: [{ file: props.file, header: h, startSeconds: 0, endSeconds: h.durationSeconds }],
        format: {
            sampleRate: h.sampleRate,
            channels: h.channels,
            bitsPerSample: h.bitsPerSample,
            bytesPerFrame: h.bytesPerFrame,
        },
        totalDurationSeconds: h.durationSeconds,
    };
    player = new PcmStreamPlayer(descriptor, {
        onTime: (t) => {
            currentTime.value = t;
            ws?.setTime(t); // drive the (media-less) cursor
            // Drop the active-region highlight once playback leaves it.
            if (activeRegionId.value) {
                const r = regions.value.find((x) => x.id === activeRegionId.value);
                if (r && (t < r.start - 0.05 || t > r.end + 0.05)) activeRegionId.value = null;
            }
        },
        onState: (p) => { isPlaying.value = p; },
    });
};

const togglePlay = () => {
    if (!player) return;
    activeRegionId.value = null; // full-track play, not a region
    if (player.isPlaying) player.pause();
    else player.play();
};

// Play just the named region: the player seeks to its start and stops at its
// end. Re-clicking the active region pauses it.
const playRegion = (id) => {
    const r = regions.value.find((x) => x.id === id);
    if (!player || !r) return;

    if (activeRegionId.value === id && isPlaying.value) {
        player.pause();
        return;
    }
    activeRegionId.value = id;
    player.playRange(r.start, r.end);
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
    scanWorker?.terminate();
    scanWorker = null;
    rmsCache = null;
    framesPerWindowCache = 0;
    regionDragUnsub?.();
    regionDragUnsub = null;

    player?.destroy();
    player = null;

    ws?.destroy();
    ws = null;
    regionsPlugin = null;
    isPlaying.value = false;
    currentTime.value = 0;
    activeRegionId.value = null;
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
                <p>Analysing audio in the background — your browser stays responsive.</p>
                <ProgressBar :value="Math.round(scanProgress * 100)" />
            </div>

            <template v-if="phase === 'ready' || phase === 'committing'">
                <div ref="waveformEl" class="dialog-waveform" />

                <div class="transport">
                    <Button
                        :icon="isPlaying && !activeRegionId ? 'pi pi-pause' : 'pi pi-play'"
                        :label="isPlaying && !activeRegionId ? 'Pause' : 'Play'"
                        size="small"
                        @click="togglePlay"
                    />
                    <span class="transport-time">
                        {{ formatTime(currentTime) }} / {{ formatTime(header?.durationSeconds ?? 0) }}
                    </span>
                </div>

                <div class="split-params">
                    <div class="split-param">
                        <label>Silence threshold <span class="split-param-val">{{ silenceDb }} dB</span></label>
                        <Slider v-model="silenceDb" :min="-80" :max="-10" :step="1" @update:model-value="applyDetection" />
                    </div>
                    <div class="split-param">
                        <label>Min silence length <span class="split-param-val">{{ minSilence }} s</span></label>
                        <Slider v-model="minSilence" :min="0.2" :max="10" :step="0.1" @update:model-value="applyDetection" />
                    </div>
                    <div class="split-param">
                        <label>Min song length <span class="split-param-val">{{ minRegion }} s</span></label>
                        <Slider v-model="minRegion" :min="1" :max="600" :step="1" @update:model-value="applyDetection" />
                    </div>
                </div>

                <div v-if="regions.length" class="region-list">
                    <div v-for="(r, i) in regions" :key="r.id" class="region-row">
                        <span class="region-index">{{ i + 1 }}</span>
                        <Button
                            :icon="activeRegionId === r.id && isPlaying ? 'pi pi-pause' : 'pi pi-play'"
                            text
                            rounded
                            size="small"
                            :aria-label="`Play region ${i + 1}`"
                            @click="playRegion(r.id)"
                        />
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
    grid-template-columns: 1.5rem auto 1fr auto auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.375rem 0;
    border-bottom: 1px solid var(--p-content-border-color);
}
.transport { display: flex; align-items: center; gap: 0.75rem; }
.transport-time {
    font-size: 0.8125rem;
    color: var(--p-text-muted-color);
    font-variant-numeric: tabular-nums;
}
.region-row:last-child { border-bottom: none; }
.region-index { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.region-name { width: 100%; }
.region-times { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; white-space: nowrap; }
.region-len { opacity: 0.7; margin-left: 0.25rem; }
.region-empty { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
</style>
