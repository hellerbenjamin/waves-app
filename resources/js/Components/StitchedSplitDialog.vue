<script setup>
import { ref, watch, onBeforeUnmount, nextTick, shallowRef, computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import ProgressBar from 'primevue/progressbar';
import Message from 'primevue/message';
import WaveSurfer from 'wavesurfer.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';
import { readWavHeaders, buildStitchedSegmentBlob } from '@/lib/wav.js';

/**
 * Stitch multiple WAV files end-to-end into one virtual timeline and let the
 * user carve it into songs entirely in the browser. The source files are
 * never uploaded — only the per-song segments leave the machine. The intended
 * input is a recording the mixer split at the 4 GB FAT boundary, where a song
 * commonly straddles two consecutive files.
 *
 * Region creation is manual: drag on empty waveform to add, drag edges to
 * resize, type names in the list below. No silence detection — live shows are
 * noisy and the auto-detect false-positive rate isn't worth the complexity.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    files: { type: Array, default: () => [] }, // File[]
});

const emit = defineEmits([
    'update:visible',
    'commit', // (segments: { name: string, blob: Blob }[])
    'cancel',
]);

const waveformEl = ref(null);

// idle → scanning → ready → committing, with `error` as a terminal off-ramp.
const phase = ref('idle');
const scanProgress = ref(0);
const errorMessage = ref('');

const stitched = shallowRef(null);
const peaks = ref([]); // Float32Array → wavesurfer accepts both
const regions = ref([]); // [{ id, start, end, name }]
const baseName = ref('');

const playerUrl = shallowRef(null); // Blob URL of the synthesized stitched WAV
const playbackUnavailable = ref(false);
const isPlaying = ref(false);
const currentTime = ref(0);
const activeRegionId = ref(null);

let ws = null;
let regionsPlugin = null;
let regionDragUnsub = null;
let suppressRegionEvents = false;

// Splice a multichannel-aware graph between wavesurfer's media element and
// the speakers so >2-channel files don't get downmixed to L/R. Matches the
// approach in SplitBeforeUploadDialog.
let audioCtx = null;
let audioSource = null;
let audioNodes = [];

let scanWorker = null;

const formatTime = (s) => {
    if (!isFinite(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
};

const formatBytes = (n) => {
    if (n == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = n;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(v < 10 && i ? 1 : 0)} ${u[i]}`;
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

const totalFilesSize = computed(() => (props.files || []).reduce((s, f) => s + (f.size || 0), 0));
const fileSummary = computed(() => (props.files || []).map((f) => `${f.name} (${formatBytes(f.size)})`));

// Open: read each header to build the stitched timeline (also validates
// format match), then kick off the peaks worker. A format mismatch surfaces
// here before the user spends time placing markers.
watch(() => props.visible, async (open) => {
    if (!open) {
        teardown();
        return;
    }
    if (!props.files?.length) return;

    phase.value = 'scanning';
    scanProgress.value = 0;
    errorMessage.value = '';
    baseName.value = (props.files[0]?.name || 'Recording').replace(/\.[^.]+$/, '') || 'Recording';
    playbackUnavailable.value = false;

    try {
        stitched.value = await readWavHeaders(props.files);
    } catch (e) {
        phase.value = 'error';
        errorMessage.value = e?.message || String(e);
        return;
    }

    // Build a synthesized full-timeline WAV Blob for playback. Zero PCM copies
    // — it's a header in front of File.slice() views. If the total PCM would
    // overflow the 32-bit RIFF size, fall back to no-preview mode (the
    // splitting math still works; we just don't play the stitched recording
    // back inside the dialog).
    try {
        const blob = buildStitchedSegmentBlob(stitched.value, { start: 0, end: stitched.value.totalDurationSeconds });
        playerUrl.value = URL.createObjectURL(blob);
    } catch {
        playerUrl.value = null;
        playbackUnavailable.value = true;
    }

    runScanInWorker();
}, { immediate: false });

const runScanInWorker = () => {
    scanWorker?.terminate();
    scanWorker = new Worker(new URL('@/lib/wavStitchedPeaksWorker.js', import.meta.url), { type: 'module' });

    scanWorker.onmessage = (event) => {
        const msg = event.data;
        if (msg.type === 'progress') {
            scanProgress.value = msg.progress;
        } else if (msg.type === 'done') {
            peaks.value = msg.peaks;
            scanWorker?.terminate();
            scanWorker = null;
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

    // Strip Vue reactivity off the source list so it survives structured-clone
    // when posted into the worker.
    scanWorker.postMessage({
        stitched: {
            sources: stitched.value.sources.map((s) => ({
                file: s.file,
                header: { ...s.header },
                startSeconds: s.startSeconds,
                endSeconds: s.endSeconds,
            })),
            format: { ...stitched.value.format },
            totalDurationSeconds: stitched.value.totalDurationSeconds,
        },
        opts: { windowMs: 20, peakStrides: 4000 },
    });
};

const renderWaveform = () => {
    if (!waveformEl.value || !peaks.value?.length || !stitched.value) return;

    ws?.destroy();
    regionsPlugin = RegionsPlugin.create();

    const config = {
        container: waveformEl.value,
        peaks: [peaks.value],
        duration: stitched.value.totalDurationSeconds,
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
    };
    if (playerUrl.value) config.url = playerUrl.value;
    ws = WaveSurfer.create(config);

    isPlaying.value = false;
    currentTime.value = 0;

    if (playerUrl.value) attachMultichannelGraph();

    ws.on('ready', () => { syncRegionsToWaveform(); });
    ws.on('play', () => { isPlaying.value = true; });
    ws.on('pause', () => { isPlaying.value = false; });
    ws.on('finish', () => { isPlaying.value = false; });
    ws.on('timeupdate', (t) => {
        currentTime.value = t;
        if (activeRegionId.value) {
            const r = regions.value.find((x) => x.id === activeRegionId.value);
            if (r && (t < r.start || t > r.end)) activeRegionId.value = null;
        }
    });

    bindRegionEvents();
    syncRegionsToWaveform();
    // Drag on empty waveform space to create a new region.
    regionDragUnsub?.();
    regionDragUnsub = regionsPlugin.enableDragSelection({ color: 'rgba(99, 102, 241, 0.15)' });
};

const attachMultichannelGraph = () => {
    const channels = stitched.value?.format?.channels ?? 0;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    const audioEl = ws?.getMediaElement?.();
    if (!Ctx || !audioEl || channels < 1) return;

    try {
        audioCtx = new Ctx();
        audioSource = audioCtx.createMediaElementSource(audioEl);
        const splitter = audioCtx.createChannelSplitter(channels);
        audioSource.connect(splitter);

        for (let i = 0; i < channels; i++) {
            const gain = audioCtx.createGain();
            gain.gain.value = 1;
            const panner = audioCtx.createStereoPanner();
            panner.pan.value = 0;
            splitter.connect(gain, i, 0);
            gain.connect(panner);
            panner.connect(audioCtx.destination);
            audioNodes.push({ gain, panner });
        }
    } catch {
        audioCtx = null;
        audioSource = null;
        audioNodes = [];
    }
};

const togglePlay = () => {
    if (!ws || !playerUrl.value) return;
    audioCtx?.resume?.();
    activeRegionId.value = null;
    ws.playPause();
};

const playRegion = (id) => {
    if (!playerUrl.value) return;
    const r = regions.value.find((x) => x.id === id);
    const regionObj = regionsPlugin?.getRegions().find((rr) => rr.id === id);
    if (!ws || !r || !regionObj) return;
    if (activeRegionId.value === id && isPlaying.value) {
        ws.pause();
        return;
    }
    audioCtx?.resume?.();
    activeRegionId.value = id;
    regionObj.play();
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
        const id = 'r' + Date.now() + Math.floor(Math.random() * 1000);
        const name = `${baseName.value} - Song ${regions.value.length + 1}`;
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

const sourceBoundaryMarkers = computed(() => {
    if (!stitched.value || !stitched.value.totalDurationSeconds) return [];
    const total = stitched.value.totalDurationSeconds;
    // Skip the leading 0 and trailing total; only the interior boundaries matter.
    return stitched.value.sources
        .slice(0, -1)
        .map((s) => ({ seconds: s.endSeconds, leftPct: (s.endSeconds / total) * 100 }));
});

const canCommit = computed(() =>
    phase.value === 'ready'
    && regions.value.length > 0
    && regions.value.every((r) => r.name.trim().length > 0 && r.end > r.start),
);

const teardown = () => {
    scanWorker?.terminate();
    scanWorker = null;

    regionDragUnsub?.();
    regionDragUnsub = null;

    audioNodes.forEach(({ gain, panner }) => {
        try { gain.disconnect(); } catch {}
        try { panner.disconnect(); } catch {}
    });
    audioNodes = [];
    try { audioSource?.disconnect(); } catch {}
    audioSource = null;
    audioCtx?.close?.().catch(() => {});
    audioCtx = null;

    ws?.destroy();
    ws = null;
    regionsPlugin = null;

    if (playerUrl.value) {
        URL.revokeObjectURL(playerUrl.value);
        playerUrl.value = null;
    }
    isPlaying.value = false;
    currentTime.value = 0;
    activeRegionId.value = null;
    regions.value = [];
    peaks.value = [];
    stitched.value = null;
    phase.value = 'idle';
    playbackUnavailable.value = false;
};

onBeforeUnmount(teardown);

let decided = false;

const close = () => emit('update:visible', false);

const onCancel = () => {
    if (decided) return;
    decided = true;
    emit('cancel');
    close();
};

const onCommit = () => {
    if (decided || !stitched.value || !canCommit.value) return;
    decided = true;
    phase.value = 'committing';

    try {
        const segments = regions.value.map((r) => ({
            name: /\.wav$/i.test(r.name.trim()) ? r.name.trim() : `${r.name.trim()}.wav`,
            blob: buildStitchedSegmentBlob(stitched.value, { start: r.start, end: r.end }),
        }));
        emit('commit', segments);
        close();
    } catch (e) {
        decided = false;
        phase.value = 'error';
        errorMessage.value = e?.message || 'Failed to assemble segments.';
    }
};

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
        :style="{ width: 'min(95vw, 64rem)' }"
        header="Stitch and split recording"
        @hide="onCancel"
    >
        <div class="stitched-dialog">
            <p class="stitched-intro">
                These files will be treated as one continuous recording. Drag on the waveform to mark a song,
                drag the edges to fine-tune, then commit — each song uploads as a separate track.
            </p>

            <div class="file-summary">
                <div class="file-summary-head">
                    <strong>{{ files.length }} file{{ files.length === 1 ? '' : 's' }}</strong>
                    <span>{{ formatBytes(totalFilesSize) }} total</span>
                </div>
                <ul>
                    <li v-for="(label, i) in fileSummary" :key="i">{{ i + 1 }}. {{ label }}</li>
                </ul>
            </div>

            <Message v-if="phase === 'error'" severity="error" :closable="false">
                {{ errorMessage || 'Could not read these WAV files.' }}
            </Message>

            <Message v-if="playbackUnavailable && phase !== 'error'" severity="warn" :closable="false">
                The combined recording exceeds 4 GB, so audio preview is disabled in this dialog. Splitting still works.
            </Message>

            <div v-if="phase === 'scanning'" class="scan-progress">
                <p>Scanning audio in the background — your browser stays responsive.</p>
                <ProgressBar :value="Math.round(scanProgress * 100)" />
            </div>

            <template v-if="phase === 'ready' || phase === 'committing'">
                <div class="waveform-wrap">
                    <div ref="waveformEl" class="dialog-waveform" />
                    <!-- Visual hints for where each source file ends inside the stitched timeline. -->
                    <div
                        v-for="(b, i) in sourceBoundaryMarkers"
                        :key="i"
                        class="boundary-marker"
                        :style="{ left: `${b.leftPct}%` }"
                        :title="`File boundary at ${formatTime(b.seconds)}`"
                    />
                </div>

                <div class="transport">
                    <Button
                        :icon="isPlaying && !activeRegionId ? 'pi pi-pause' : 'pi pi-play'"
                        :label="isPlaying && !activeRegionId ? 'Pause' : 'Play'"
                        size="small"
                        :disabled="!playerUrl"
                        @click="togglePlay"
                    />
                    <span class="transport-time">
                        {{ formatTime(currentTime) }} / {{ formatTime(stitched?.totalDurationSeconds ?? 0) }}
                    </span>
                </div>

                <div v-if="regions.length" class="region-list">
                    <div v-for="(r, i) in regions" :key="r.id" class="region-row">
                        <span class="region-index">{{ i + 1 }}</span>
                        <Button
                            :icon="activeRegionId === r.id && isPlaying ? 'pi pi-pause' : 'pi pi-play'"
                            text
                            rounded
                            size="small"
                            :disabled="!playerUrl"
                            :aria-label="`Play song ${i + 1}`"
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
                            aria-label="Remove song"
                            @click="removeRegion(r.id)"
                        />
                    </div>
                </div>
                <p v-else class="region-empty">
                    Drag across the waveform to mark a song region.
                </p>
            </template>
        </div>

        <template #footer>
            <Button label="Cancel" text severity="secondary" :disabled="phase === 'committing'" @click="onCancel" />
            <Button
                label="Upload songs"
                icon="pi pi-upload"
                severity="success"
                :loading="phase === 'committing'"
                :disabled="!canCommit"
                @click="onCommit"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.stitched-dialog { display: flex; flex-direction: column; gap: 1rem; }
.stitched-intro { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }

.file-summary {
    border: 1px solid var(--p-content-border-color);
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--p-content-background);
}
.file-summary-head { display: flex; justify-content: space-between; align-items: baseline; font-size: 0.875rem; }
.file-summary ul { margin: 0.375rem 0 0; padding-left: 1.125rem; font-size: 0.8125rem; color: var(--p-text-muted-color); }

.waveform-wrap { position: relative; }
.dialog-waveform { width: 100%; }
.boundary-marker {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 0;
    border-left: 1px dashed var(--p-text-muted-color);
    pointer-events: none;
    opacity: 0.55;
}

.scan-progress { display: flex; flex-direction: column; gap: 0.5rem; }
.scan-progress p { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }

.region-list { display: flex; flex-direction: column; gap: 0.5rem; }
.region-row {
    display: grid;
    grid-template-columns: 1.5rem auto 1fr auto auto;
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

.transport { display: flex; align-items: center; gap: 0.75rem; }
.transport-time { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
</style>
