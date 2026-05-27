<script setup>
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Card from 'primevue/card';
import Message from 'primevue/message';
import Slider from 'primevue/slider';
import Select from 'primevue/select';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import WaveSurfer from 'wavesurfer.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';

const props = defineProps({
    track: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    mixSources: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: true },
});

// Owners get the app chrome; public share links render under a guest layout.
const Layout = props.canEdit ? AuthenticatedLayout : PublicLayout;

const waveformEl = ref(null);
const isPlaying = ref(false);
const isReady = ref(false);
const currentTime = ref(0);
const loadError = ref(null);

// Local source of truth for the (editable) track name so the heading and the
// document title update immediately on rename, without a full Inertia reload.
const trackName = ref(props.track.name);

// Per-channel mixer state. `levels` is 0–100 (%), `pans` is -100 (L) to 100 (R),
// `muted` toggles each channel, `labels` are user-supplied channel names.
const levels = ref([]);
const pans = ref([]);
const muted = ref([]);
const labels = ref([]);
const mixerUnavailable = ref(false);

// Saved channel-name templates the user can apply to this (or any) track.
const templateList = ref([...props.templates]);
const selectedTemplate = ref(null);
const showSaveDialog = ref(false);
const newTemplateName = ref('');

let ws = null;
let audioCtx = null;
let gainNodes = [];
let panners = [];
let pollTimer = null;
let regions = null; // wavesurfer Regions plugin instance
let suppressRegionEvents = false; // guard against feedback loops during sync

const channelCount = () => props.track.channels_count ?? 0;

// Peaks envelope fetched async from object storage (see initWaveform).
const peaksChannels = ref(null);

const channelLabel = (i, total) => {
    if (total <= 1) return 'Mono';
    if (total === 2) return i === 0 ? 'L' : 'R';
    return `Ch ${i + 1}`;
};

const formatBytes = (n) => {
    if (n == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
};

const formatTime = (s) => {
    if (s == null || Number.isNaN(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
};

const cssVar = (name, fallback) => {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
};

// Split the media element into one gain-controlled lane per channel, then fold
// every channel down into the stereo output. A plain <audio> element only
// exposes a single master volume, so per-channel faders require the Web Audio
// graph. Each lane runs through a (currently centered) StereoPanner so all
// channels reach both L and R — merging into a >2-channel bus would let the
// stereo destination drop everything past the first two. Per-channel panning
// is a later step: the panner nodes are already in place for it.
const setupMixer = (audioEl, channels, corsOk) => {
    const Ctx = window.AudioContext || window.webkitAudioContext;

    // Skip the graph when there's no Web Audio, nothing to split, or the source
    // isn't readable cross-origin. Routing a CORS-tainted element through Web
    // Audio would silently mute it, so in that case we leave the element to play
    // on its own — audible, just without per-channel faders.
    if (!Ctx || channels < 1 || !corsOk) {
        mixerUnavailable.value = true;
        return;
    }

    try {
        audioCtx = new Ctx();
        const source = audioCtx.createMediaElementSource(audioEl);
        const splitter = audioCtx.createChannelSplitter(channels);

        source.connect(splitter);

        for (let i = 0; i < channels; i++) {
            const gain = audioCtx.createGain();
            gain.gain.value = 1;

            const panner = audioCtx.createStereoPanner();
            panner.pan.value = 0; // centered for now; per-channel pan comes later

            splitter.connect(gain, i, 0);
            gain.connect(panner);
            panner.connect(audioCtx.destination);

            gainNodes.push(gain);
            panners.push(panner);
        }

        // Seed from a saved default mix when present (the owner's saved
        // levels/pans/mutes are persisted on the track and apply to shared
        // viewers too). Anything past the saved length falls back to the
        // unity defaults so a re-encoded source with extra channels still loads.
        const saved = props.track.default_mix ?? null;
        levels.value = Array.from({ length: channels }, (_, i) => saved?.[i]?.level ?? 100);
        pans.value = Array.from({ length: channels }, (_, i) => saved?.[i]?.pan ?? 0);
        muted.value = Array.from({ length: channels }, (_, i) => !!saved?.[i]?.muted);
        labels.value = Array.from({ length: channels }, (_, i) => props.track.channel_labels?.[i] ?? '');

        // Push the seeded state into the audio graph directly (instantaneous,
        // pre-playback) so the first playback already reflects the saved mix.
        for (let i = 0; i < channels; i++) {
            gainNodes[i].gain.value = muted.value[i] ? 0 : levels.value[i] / 100;
            panners[i].pan.value = pans.value[i] / 100;
        }
    } catch (e) {
        // A tainted (cross-origin) media source throws here; fall back to plain
        // playback without per-channel control.
        mixerUnavailable.value = true;
    }
};

const applyGain = (i) => {
    const node = gainNodes[i];
    if (!node || !audioCtx) return;
    const value = muted.value[i] ? 0 : levels.value[i] / 100;
    node.gain.setTargetAtTime(value, audioCtx.currentTime, 0.015);
};

const toggleMute = (i) => {
    muted.value[i] = !muted.value[i];
    applyGain(i);
};

const applyPan = (i) => {
    const node = panners[i];
    if (!node || !audioCtx) return;
    node.pan.setTargetAtTime(pans.value[i] / 100, audioCtx.currentTime, 0.01);
};

const resetPan = (i) => {
    pans.value[i] = 0;
    applyPan(i);
};

const panLabel = (v) => (v === 0 ? 'C' : v < 0 ? `L${-v}` : `R${v}`);

const labelsStatus = ref(''); // '' | 'saving' | 'saved'
let savedTimer = null;
let labelInputs = [];

const setLabelRef = (el, i) => {
    if (el) labelInputs[i] = el;
};

// Tab/Shift+Tab jumps straight to the adjacent channel label, skipping the
// faders and pan controls in between. At the ends, fall back to normal Tab.
const focusAdjacentLabel = (event, i) => {
    const next = labelInputs[i + (event.shiftKey ? -1 : 1)];
    if (next) {
        event.preventDefault();
        next.focus();
        next.select();
    }
};

const csrfToken = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

const apiFetch = (url, { method, body }) => fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
});

const saveLabels = async () => {
    labelsStatus.value = 'saving';
    try {
        const res = await apiFetch(route('tracks.update', props.track.id), {
            method: 'PATCH',
            body: { channel_labels: labels.value.map((l) => l.trim() || null) },
        });
        if (!res.ok) throw new Error(`save failed (${res.status})`);

        labelsStatus.value = 'saved';
        clearTimeout(savedTimer);
        savedTimer = setTimeout(() => { labelsStatus.value = ''; }, 1500);
    } catch (e) {
        labelsStatus.value = '';
    }
};

// Default mix: owner-only Save/Clear that persists the current per-channel
// state. Loaded automatically by setupMixer on every visit (owner or shared).
const mixStatus = ref(''); // '' | 'saving' | 'saved'
let mixStatusTimer = null;
const hasDefaultMix = ref(!!props.track.default_mix);

const saveDefaultMix = async () => {
    mixStatus.value = 'saving';
    try {
        const payload = levels.value.map((lvl, i) => ({
            level: lvl,
            pan: pans.value[i] ?? 0,
            muted: !!muted.value[i],
        }));
        const res = await apiFetch(route('tracks.update', props.track.id), {
            method: 'PATCH',
            body: { default_mix: payload },
        });
        if (!res.ok) throw new Error('save failed');
        hasDefaultMix.value = true;
        mixStatus.value = 'saved';
        clearTimeout(mixStatusTimer);
        mixStatusTimer = setTimeout(() => { mixStatus.value = ''; }, 1500);
    } catch (e) {
        mixStatus.value = '';
    }
};

// "Copy mix from…": load another of the user's tracks' saved default_mix into
// the live faders so they can audition (and tweak) before hitting Save default
// mix themselves. Mirrors how applyTemplate copies labels into the live row.
const mixSourceList = ref([...props.mixSources]);
const selectedMixSource = ref(null);

const applyMixSource = (source) => {
    if (!source?.default_mix) {
        selectedMixSource.value = null;
        return;
    }
    const channels = channelCount();
    for (let i = 0; i < channels; i++) {
        const entry = source.default_mix[i];
        if (!entry) continue;
        levels.value[i] = entry.level ?? 100;
        pans.value[i] = entry.pan ?? 0;
        muted.value[i] = !!entry.muted;
        applyGain(i);
        applyPan(i);
    }
    selectedMixSource.value = null; // snap the picker back to the placeholder
};

const clearDefaultMix = async () => {
    try {
        const res = await apiFetch(route('tracks.update', props.track.id), {
            method: 'PATCH',
            body: { default_mix: null },
        });
        if (!res.ok) throw new Error('clear failed');
        hasDefaultMix.value = false;
        mixStatus.value = 'saved';
        clearTimeout(mixStatusTimer);
        mixStatusTimer = setTimeout(() => { mixStatus.value = ''; }, 1500);
    } catch (e) {
        // ignore
    }
};

const saveName = async () => {
    const name = trackName.value.trim();
    // Ignore no-ops and empty names; snap the field back to the last good value.
    if (!name || name === props.track.name) {
        trackName.value = props.track.name;
        return;
    }
    try {
        const res = await apiFetch(route('tracks.update', props.track.id), {
            method: 'PATCH',
            body: { original_name: name },
        });
        if (!res.ok) throw new Error(`rename failed (${res.status})`);
        props.track.name = (await res.json()).name;
        trackName.value = props.track.name;
    } catch (e) {
        trackName.value = props.track.name;
    }
};

// Apply a template's ordered labels onto this track's channels by index
// (extra template entries are ignored, missing ones clear the channel).
const applyTemplate = (template) => {
    if (!template) return;
    labels.value = labels.value.map((_, i) => template.labels?.[i] ?? '');
    selectedTemplate.value = null; // leave the picker on its placeholder
    saveLabels();
};

const openSaveTemplate = () => {
    newTemplateName.value = (props.track.name || '').replace(/\.[^.]+$/, '');
    showSaveDialog.value = true;
};

const saveTemplate = async () => {
    const name = newTemplateName.value.trim();
    if (!name) return;
    try {
        const res = await apiFetch(route('channel-templates.store'), {
            method: 'POST',
            body: { name, labels: labels.value.map((l) => l.trim() || null) },
        });
        if (!res.ok) throw new Error(`save failed (${res.status})`);
        templateList.value.unshift(await res.json());
        showSaveDialog.value = false;
    } catch (e) {
        // leave the dialog open so the user can retry
    }
};

const deleteTemplate = async (template) => {
    try {
        const res = await apiFetch(route('channel-templates.destroy', template.id), { method: 'DELETE' });
        if (!res.ok) throw new Error('delete failed');
        templateList.value = templateList.value.filter((t) => t.id !== template.id);
    } catch (e) {
        // ignore
    }
};

// Public sharing (owner only).
const shareUrl = ref(props.track.share_url ?? null);
const showShareDialog = ref(false);
const shareBusy = ref(false);
const copied = ref(false);

const openShare = () => { showShareDialog.value = true; };

const enableShare = async () => {
    shareBusy.value = true;
    try {
        const res = await apiFetch(route('tracks.share', props.track.id), { method: 'POST', body: {} });
        if (!res.ok) throw new Error('share failed');
        shareUrl.value = (await res.json()).share_url;
    } catch (e) {
        // ignore
    } finally {
        shareBusy.value = false;
    }
};

const disableShare = async () => {
    shareBusy.value = true;
    try {
        const res = await apiFetch(route('tracks.unshare', props.track.id), { method: 'DELETE' });
        if (!res.ok) throw new Error('unshare failed');
        shareUrl.value = null;
    } catch (e) {
        // ignore
    } finally {
        shareBusy.value = false;
    }
};

const copyShareUrl = async () => {
    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (e) {
        // clipboard blocked; the field is selectable as a fallback
    }
};

// Whether the stream's bytes are readable from this origin. Same-origin routes
// always are; an off-origin presigned (S3/R2) URL depends on the bucket's CORS,
// so probe it with a one-byte ranged request that mirrors how the player reads.
const streamReachable = async () => {
    if (props.track.stream_cross_origin !== 'anonymous') return true;
    try {
        const res = await fetch(props.track.stream_url, { headers: { Range: 'bytes=0-0' } });
        return res.ok || res.status === 206;
    } catch {
        return false; // CORS-blocked or unreachable
    }
};

// Pull the peaks envelope from object storage. Returns null on any failure —
// the waveform then renders empty rather than failing the whole page, and the
// regenerate path is `tracks:reprocess` on the server.
const loadPeaks = async () => {
    if (!props.track.peaks_url) return null;
    try {
        const res = await fetch(props.track.peaks_url, {
            // Owner pages get a presigned (no-cookies) URL; share/local routes
            // are same-origin and need the session cookie.
            credentials: props.track.stream_cross_origin === 'anonymous' ? 'omit' : 'include',
        });
        if (!res.ok) return null;
        return await res.json();
    } catch {
        return null;
    }
};

let initStarted = false;

const initWaveform = async () => {
    if (initStarted || !waveformEl.value) return;
    initStarted = true; // guard the await window against a second trigger

    const channels = channelCount();

    // Fetch the peaks envelope from object storage in parallel with the CORS
    // probe. Peaks used to ride inline in the Inertia payload, but they can be
    // MB-large per track and would balloon the HTML response; pulling them
    // separately lets the page render while the waveform data is on the wire.
    const [corsOk, envelope] = await Promise.all([
        streamReachable(),
        loadPeaks(),
    ]);

    peaksChannels.value = envelope?.channels ?? null;

    // Stream playback through a media element so large files seek via HTTP
    // range requests instead of being fully downloaded; the waveform itself
    // renders immediately from the pre-computed peaks.
    const audio = new Audio();
    audio.preload = 'metadata';
    if (corsOk) audio.crossOrigin = props.track.stream_cross_origin;
    audio.src = props.track.stream_url;

    regions = RegionsPlugin.create();

    ws = WaveSurfer.create({
        container: waveformEl.value,
        media: audio,
        peaks: peaksChannels.value ?? undefined,
        duration: props.track.duration_seconds ?? undefined,
        splitChannels: channels > 1 ? Array.from({ length: channels }, () => ({})) : undefined,
        height: channels > 1 ? 64 : 140,
        waveColor: cssVar('--p-primary-200', '#c7d2fe'),
        progressColor: cssVar('--p-primary-color', '#6366f1'),
        cursorColor: cssVar('--p-text-color', '#1f2937'),
        cursorWidth: 2,
        barWidth: 2,
        barGap: 1,
        barRadius: 2,
        normalize: false,
        plugins: [regions],
    });

    // Drag on empty waveform space to add a new region. Disabled until the
    // user is actively staging a split (otherwise plain seeking is impaired).
    bindRegionEvents();

    setupMixer(audio, channels, corsOk);

    ws.on('ready', () => { isReady.value = true; });
    ws.on('play', () => { isPlaying.value = true; audioCtx?.resume(); });
    ws.on('pause', () => { isPlaying.value = false; });
    ws.on('finish', () => { isPlaying.value = false; });
    ws.on('timeupdate', (t) => { currentTime.value = t; });
    ws.on('error', (e) => { loadError.value = e?.message || String(e); });
    ws.on('ready', () => {
        syncRegionsToWaveform();
        enableRegionCreate(proposal.value?.status === 'ready');
        // Keep the detection-poll alive if the page loaded mid-detection.
        if (proposal.value?.status === 'detecting') startDetectPolling();
    });
};

// Split-into-songs UI state. `proposal` mirrors the server's split_proposal:
// when it's null there's no staging in progress. The sliders feed the next
// detection run; we also persist them locally to the proposal between runs
// so reopening the page restores what the user was tweaking.
const proposal = ref(props.track.split_proposal ?? null);
const children = ref([...(props.track.children ?? [])]);
const splitBusy = ref(false);
const splitDb = ref(props.track.split_proposal?.params?.silence_db ?? -40);
const splitMinSilence = ref(props.track.split_proposal?.params?.min_silence ?? 1.5);
const splitMinRegion = ref(props.track.split_proposal?.params?.min_region ?? 30);
let detectPoll = null;
let saveProposalTimer = null;

const splitActive = () => proposal.value && proposal.value.status === 'ready' && (proposal.value.regions?.length || 0) >= 0;

const regionColor = (i) => {
    // Alternating translucent fills so adjacent regions stay visually distinct
    // over the waveform.
    const palette = [
        'rgba(99, 102, 241, 0.20)',
        'rgba(16, 185, 129, 0.20)',
        'rgba(244, 114, 182, 0.20)',
        'rgba(245, 158, 11, 0.20)',
    ];
    return palette[i % palette.length];
};

// Push the proposal's regions onto the waveform. Recreates the set on every
// sync so deletes and renames stay consistent; suppressed events keep this
// from echoing back as fake user edits.
const syncRegionsToWaveform = () => {
    if (!regions || !ws) return;
    suppressRegionEvents = true;
    try {
        regions.clearRegions();
        if (!proposal.value || proposal.value.status !== 'ready') return;
        (proposal.value.regions ?? []).forEach((r, i) => {
            regions.addRegion({
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

const bindRegionEvents = () => {
    if (!regions) return;

    // User dragged an edge or the whole region.
    regions.on('region-updated', (region) => {
        if (suppressRegionEvents || !proposal.value) return;
        const target = (proposal.value.regions ?? []).find((r) => r.id === region.id);
        if (!target) return;
        target.start = Number(region.start.toFixed(3));
        target.end = Number(region.end.toFixed(3));
        scheduleProposalSave();
    });

    // Drag-to-create on empty space (toggled on only while staging a split).
    regions.on('region-created', (region) => {
        if (suppressRegionEvents || !proposal.value) return;
        // Skip rebuilds we triggered ourselves in syncRegionsToWaveform.
        if ((proposal.value.regions ?? []).some((r) => r.id === region.id)) return;
        const existing = proposal.value.regions ?? [];
        const id = 'r' + Date.now();
        const name = `Part ${existing.length + 1}`;
        region.setOptions({ id, content: name, color: regionColor(existing.length) });
        proposal.value.regions = [
            ...existing,
            { id, start: Number(region.start.toFixed(3)), end: Number(region.end.toFixed(3)), name },
        ];
        scheduleProposalSave();
    });
};

// Toggle drag-to-create on the waveform body. Off during normal playback so a
// click still seeks; on while the user is staging a split. The plugin's
// enableDragSelection() returns an unsubscribe fn we hold onto.
let regionDragUnsub = null;
const enableRegionCreate = (on) => {
    if (!regions) return;
    regionDragUnsub?.();
    regionDragUnsub = null;
    if (on) {
        regionDragUnsub = regions.enableDragSelection({
            color: 'rgba(99, 102, 241, 0.15)',
        });
    }
};

const scheduleProposalSave = () => {
    clearTimeout(saveProposalTimer);
    saveProposalTimer = setTimeout(saveProposal, 400);
};

const saveProposal = async () => {
    if (!proposal.value) return;
    try {
        await apiFetch(route('tracks.split-proposal.update', props.track.id), {
            method: 'PATCH',
            body: { regions: proposal.value.regions ?? [] },
        });
    } catch (e) {
        // Surface nothing to the user — they'll see stale state and retry by
        // dragging again. A persistent failure shows up as the next reload.
    }
};

const startDetect = async () => {
    splitBusy.value = true;
    try {
        const res = await apiFetch(route('tracks.detect-songs', props.track.id), {
            method: 'POST',
            body: {
                silence_db: splitDb.value,
                min_silence: splitMinSilence.value,
                min_region: splitMinRegion.value,
            },
        });
        if (!res.ok) throw new Error('detect failed');
        proposal.value = (await res.json()).split_proposal;
        startDetectPolling();
    } catch (e) {
        // ignore — user can retry
    } finally {
        splitBusy.value = false;
    }
};

const startDetectPolling = () => {
    clearInterval(detectPoll);
    detectPoll = setInterval(() => {
        router.reload({ only: ['track'], preserveScroll: true });
    }, 3000);
};

const removeRegion = (id) => {
    if (!proposal.value) return;
    proposal.value.regions = (proposal.value.regions ?? []).filter((r) => r.id !== id);
    syncRegionsToWaveform();
    scheduleProposalSave();
};

const renameRegion = (id, name) => {
    if (!proposal.value) return;
    const r = (proposal.value.regions ?? []).find((x) => x.id === id);
    if (!r) return;
    r.name = name;
    // Update the on-waveform label without rebuilding all regions.
    regions?.getRegions().find((rr) => rr.id === id)?.setOptions({ content: name });
    scheduleProposalSave();
};

const discardProposal = async () => {
    try {
        await apiFetch(route('tracks.split-proposal.destroy', props.track.id), { method: 'DELETE' });
        proposal.value = null;
        syncRegionsToWaveform();
    } catch (e) {
        // ignore
    }
};

const commitSplit = async () => {
    splitBusy.value = true;
    try {
        const res = await apiFetch(route('tracks.split', props.track.id), { method: 'POST', body: {} });
        if (!res.ok) throw new Error('split failed');
        proposal.value = null;
        syncRegionsToWaveform();
        // Children land asynchronously; reload until the count stops climbing.
        router.reload({ only: ['track'], preserveScroll: true });
    } catch (e) {
        // ignore
    } finally {
        splitBusy.value = false;
    }
};

// Mirror server state into local refs whenever Inertia partial-reloads.
watch(() => props.track.split_proposal, (next) => {
    proposal.value = next ?? null;
    if (!next || next.status !== 'detecting') {
        clearInterval(detectPoll);
        detectPoll = null;
    }
    syncRegionsToWaveform();
    enableRegionCreate(!!next && next.status === 'ready');
}, { deep: true });

watch(() => props.track.children, (next) => {
    children.value = [...(next ?? [])];
}, { deep: true });

const formatDurationShort = formatTime;


const togglePlay = () => ws?.playPause();

const restart = () => {
    ws?.seekTo(0);
    ws?.play();
};

onMounted(() => {
    if (props.track.peaks_ready) {
        nextTick(initWaveform);
    } else {
        // Peaks are generated by a queued job; poll until they land.
        pollTimer = setInterval(() => {
            router.reload({ only: ['track'], preserveScroll: true });
        }, 4000);
    }
});

// When polling refreshes the prop and peaks become ready, build the player.
watch(() => props.track.peaks_ready, (ready) => {
    if (ready && !ws) {
        clearInterval(pollTimer);
        pollTimer = null;
        nextTick(initWaveform);
    }
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(detectPoll);
    clearTimeout(savedTimer);
    clearTimeout(mixStatusTimer);
    clearTimeout(saveProposalTimer);
    regionDragUnsub?.();
    regionDragUnsub = null;
    regions = null;
    ws?.destroy();
    ws = null;
    audioCtx?.close();
    audioCtx = null;
    gainNodes = [];
    panners = [];
    pans.value = [];
    labels.value = [];
    labelInputs = [];
});
</script>

<template>
    <Head :title="trackName" />

    <component :is="Layout">
        <template #header>
            <div class="header-row">
                <Link v-if="canEdit" :href="route('tracks.index')" class="back-link">
                    <i class="pi pi-arrow-left" />
                    <span>Tracks</span>
                </Link>
                <div v-if="canEdit" class="title-field">
                    <input
                        v-model="trackName"
                        class="page-title-input"
                        maxlength="255"
                        aria-label="Track name"
                        @blur="saveName"
                        @keyup.enter="$event.target.blur()"
                    />
                </div>
                <h2 v-else class="page-title">{{ trackName }}</h2>
                <Tag v-if="!canEdit" value="Shared" severity="info" />
                <Button
                    v-if="canEdit"
                    class="download-btn"
                    icon="pi pi-download"
                    label="Download"
                    severity="secondary"
                    outlined
                    size="small"
                    :as="'a'"
                    :href="route('tracks.download', track.id)"
                />
                <Button
                    v-if="canEdit"
                    :icon="shareUrl ? 'pi pi-link' : 'pi pi-share-alt'"
                    :label="shareUrl ? 'Sharing' : 'Share'"
                    :severity="shareUrl ? 'success' : 'secondary'"
                    :outlined="!shareUrl"
                    size="small"
                    @click="openShare"
                />
            </div>
        </template>

        <div class="stack">
            <Card>
                <template #content>
                    <Message v-if="loadError" severity="error" :closable="false">
                        Couldn't load audio: {{ loadError }}
                    </Message>

                    <template v-if="track.peaks_ready">
                        <div ref="waveformEl" class="waveform" :class="{ 'is-loading': !isReady }" />

                        <div class="controls">
                            <Button
                                :icon="isPlaying ? 'pi pi-pause' : 'pi pi-play'"
                                :label="isPlaying ? 'Pause' : 'Play'"
                                :disabled="!isReady"
                                @click="togglePlay"
                            />
                            <Button
                                icon="pi pi-replay"
                                text
                                rounded
                                :disabled="!isReady"
                                aria-label="Restart"
                                @click="restart"
                            />
                            <span class="time">
                                {{ formatTime(currentTime) }} / {{ formatTime(track.duration_seconds) }}
                            </span>
                        </div>
                    </template>

                    <div v-else class="processing">
                        <i class="pi pi-spin pi-spinner" />
                        <div>
                            <p class="processing-title">Generating waveform…</p>
                            <p class="processing-sub">This refreshes automatically when it's ready.</p>
                        </div>
                    </div>
                </template>
            </Card>

            <Card v-if="track.peaks_ready && levels.length">
                <template #title>
                    <div class="mixer-header">
                        <span class="mixer-title">Channel mixer</span>
                        <div v-if="canEdit" class="mixer-actions">
                            <span class="mixer-status" :class="{ visible: labelsStatus || mixStatus }">
                                <template v-if="labelsStatus === 'saving' || mixStatus === 'saving'">Saving…</template>
                                <template v-else-if="labelsStatus === 'saved' || mixStatus === 'saved'"><i class="pi pi-check" /> Saved</template>
                            </span>
                            <Select
                                v-if="templateList.length"
                                v-model="selectedTemplate"
                                :options="templateList"
                                option-label="name"
                                placeholder="Apply template"
                                class="template-select"
                                @update:model-value="applyTemplate"
                            >
                                <template #option="{ option }">
                                    <div class="template-option">
                                        <span>{{ option.name }}</span>
                                        <i
                                            class="pi pi-trash template-option-del"
                                            :aria-label="`Delete template ${option.name}`"
                                            @click.stop.prevent="deleteTemplate(option)"
                                        />
                                    </div>
                                </template>
                            </Select>
                            <Button label="Save as template" icon="pi pi-bookmark" size="small" outlined @click="openSaveTemplate" />
                            <Select
                                v-if="mixSourceList.length"
                                v-model="selectedMixSource"
                                :options="mixSourceList"
                                option-label="name"
                                placeholder="Copy mix from…"
                                class="template-select"
                                @update:model-value="applyMixSource"
                            />
                            <Button
                                :label="hasDefaultMix ? 'Update default mix' : 'Save default mix'"
                                icon="pi pi-sliders-h"
                                size="small"
                                outlined
                                @click="saveDefaultMix"
                            />
                            <Button
                                v-if="hasDefaultMix"
                                label="Clear default"
                                icon="pi pi-times"
                                size="small"
                                text
                                severity="secondary"
                                @click="clearDefaultMix"
                            />
                        </div>
                    </div>
                </template>
                <template #content>
                    <p v-if="canEdit" class="mixer-hint">Click a channel name to rename it</p>
                    <div class="mixer">
                        <div v-for="(lvl, i) in levels" :key="i" class="fader" :class="{ muted: muted[i] }">
                            <span class="fader-val">{{ muted[i] ? '—' : `${lvl}%` }}</span>
                            <Slider
                                v-model="levels[i]"
                                orientation="vertical"
                                :min="0"
                                :max="100"
                                :disabled="muted[i]"
                                class="fader-slider"
                                @update:model-value="applyGain(i)"
                            />
                            <Button
                                :icon="muted[i] ? 'pi pi-volume-off' : 'pi pi-volume-up'"
                                :severity="muted[i] ? 'danger' : 'secondary'"
                                text
                                rounded
                                size="small"
                                :aria-label="`Mute ${channelLabel(i, levels.length)}`"
                                @click="toggleMute(i)"
                            />
                            <div v-if="canEdit" class="label-field">
                                <input
                                    :ref="(el) => setLabelRef(el, i)"
                                    v-model="labels[i]"
                                    class="fader-label-input"
                                    :placeholder="channelLabel(i, levels.length)"
                                    maxlength="60"
                                    :aria-label="`Label for ${channelLabel(i, levels.length)}`"
                                    @blur="saveLabels"
                                    @keyup.enter="$event.target.blur()"
                                    @keydown.tab="focusAdjacentLabel($event, i)"
                                />
                            </div>
                            <span v-else class="fader-label">{{ labels[i] || channelLabel(i, levels.length) }}</span>

                            <div class="pan">
                                <Slider
                                    v-model="pans[i]"
                                    :min="-100"
                                    :max="100"
                                    class="pan-slider"
                                    :aria-label="`Pan ${channelLabel(i, levels.length)}`"
                                    @update:model-value="applyPan(i)"
                                />
                                <button type="button" class="pan-val" title="Double-click to center" @dblclick="resetPan(i)">
                                    {{ panLabel(pans[i]) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <Message v-else-if="track.peaks_ready && mixerUnavailable" severity="warn" :closable="false">
                Per-channel faders aren't available for this audio source (the browser couldn't access its channels).
            </Message>

            <Card v-if="canEdit && track.peaks_ready">
                <template #title>
                    <div class="split-header">
                        <span class="split-title">Split into songs</span>
                        <span v-if="proposal?.status === 'detecting'" class="split-status">
                            <i class="pi pi-spin pi-spinner" /> Detecting…
                        </span>
                    </div>
                </template>
                <template #content>
                    <p class="split-hint">
                        ffmpeg looks for silence to guess where songs begin and end. Tune the thresholds, then drag the
                        edges of each region on the waveform above to fine-tune. Drag on empty waveform space to add a new
                        region.
                    </p>

                    <div class="split-params">
                        <div class="split-param">
                            <label>Silence threshold <span class="split-param-val">{{ splitDb }} dB</span></label>
                            <Slider v-model="splitDb" :min="-80" :max="-10" :step="1" />
                            <p class="split-param-hint">Anything quieter than this counts as silence.</p>
                        </div>
                        <div class="split-param">
                            <label>Min silence length <span class="split-param-val">{{ splitMinSilence }} s</span></label>
                            <Slider v-model="splitMinSilence" :min="0.2" :max="10" :step="0.1" />
                            <p class="split-param-hint">Ignore quiet stretches shorter than this.</p>
                        </div>
                        <div class="split-param">
                            <label>Min song length <span class="split-param-val">{{ splitMinRegion }} s</span></label>
                            <Slider v-model="splitMinRegion" :min="1" :max="600" :step="1" />
                            <p class="split-param-hint">Drop candidate songs shorter than this.</p>
                        </div>
                    </div>

                    <div class="split-actions">
                        <Button
                            :icon="proposal?.status === 'detecting' ? 'pi pi-spin pi-spinner' : 'pi pi-search'"
                            :label="proposal?.regions?.length ? 'Re-detect' : 'Detect songs'"
                            :disabled="splitBusy || proposal?.status === 'detecting'"
                            @click="startDetect"
                        />
                        <Button
                            v-if="proposal?.status === 'ready' && proposal?.regions?.length"
                            label="Commit split"
                            icon="pi pi-check"
                            severity="success"
                            :loading="splitBusy"
                            @click="commitSplit"
                        />
                        <Button
                            v-if="proposal?.regions?.length"
                            label="Discard"
                            text
                            severity="secondary"
                            @click="discardProposal"
                        />
                    </div>

                    <div v-if="proposal?.regions?.length" class="region-list">
                        <div v-for="(r, i) in proposal.regions" :key="r.id" class="region-row">
                            <span class="region-index">{{ i + 1 }}</span>
                            <InputText
                                :model-value="r.name"
                                class="region-name"
                                @update:model-value="(v) => renameRegion(r.id, v)"
                            />
                            <span class="region-times">
                                {{ formatDurationShort(r.start) }} – {{ formatDurationShort(r.end) }}
                                <span class="region-len">({{ formatDurationShort(r.end - r.start) }})</span>
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
                    <p v-else-if="proposal?.status === 'ready'" class="split-empty">
                        No regions long enough were found. Lower the minimum song length or the silence threshold.
                    </p>
                </template>
            </Card>

            <Card v-if="children.length">
                <template #title>
                    <span class="split-title">Split from this track</span>
                </template>
                <template #content>
                    <ul class="child-list">
                        <li v-for="c in children" :key="c.id" class="child-row">
                            <Link :href="route('tracks.show', c.id)" class="child-name">{{ c.name }}</Link>
                            <span class="child-time">{{ formatDurationShort(c.duration_seconds) }}</span>
                            <Tag
                                v-if="!c.peaks_ready"
                                value="Processing"
                                severity="warn"
                            />
                        </li>
                    </ul>
                </template>
            </Card>

            <Card>
                <template #content>
                    <dl class="meta">
                        <div class="meta-row">
                            <dt>Status</dt>
                            <dd>
                                <Tag v-if="track.peaks_ready" severity="success" value="Ready" />
                                <Tag v-else severity="warn" value="Processing" />
                            </dd>
                        </div>
                        <div class="meta-row">
                            <dt>Channels</dt>
                            <dd>{{ channelCount() || '—' }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Duration</dt>
                            <dd>{{ formatTime(track.duration_seconds) }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Size</dt>
                            <dd>{{ formatBytes(track.size) }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Format</dt>
                            <dd>{{ track.mime || '—' }}</dd>
                        </div>
                    </dl>
                </template>
            </Card>
        </div>

        <Dialog v-if="canEdit" v-model:visible="showSaveDialog" modal header="Save channel template" :style="{ width: '24rem' }">
            <div class="save-dialog">
                <label for="tpl-name">Template name</label>
                <InputText id="tpl-name" v-model="newTemplateName" autofocus @keyup.enter="saveTemplate" />
                <p class="save-dialog-hint">Saves the current channel names as a reusable, ordered template you can apply to other tracks.</p>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showSaveDialog = false" />
                <Button label="Save" icon="pi pi-bookmark" :disabled="!newTemplateName.trim()" @click="saveTemplate" />
            </template>
        </Dialog>

        <Dialog v-if="canEdit" v-model:visible="showShareDialog" modal header="Share this track" :style="{ width: '26rem' }">
            <div class="share-dialog">
                <template v-if="shareUrl">
                    <p class="share-dialog-hint">Anyone with this link can play the track — no account needed.</p>
                    <div class="share-link-row">
                        <InputText :model-value="shareUrl" readonly class="share-link" @focus="$event.target.select()" />
                        <Button :icon="copied ? 'pi pi-check' : 'pi pi-copy'" :label="copied ? 'Copied' : 'Copy'" @click="copyShareUrl" />
                    </div>
                </template>
                <p v-else class="share-dialog-hint">Create a public link to let anyone play this track without signing in.</p>
            </div>
            <template #footer>
                <Button v-if="shareUrl" label="Stop sharing" severity="danger" text :loading="shareBusy" @click="disableShare" />
                <Button v-else label="Create public link" icon="pi pi-share-alt" :loading="shareBusy" @click="enableShare" />
            </template>
        </Dialog>
    </component>
</template>

<style scoped>
.header-row { display: flex; align-items: center; gap: 1rem; }
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.title-field { position: relative; flex: 1; min-width: 0; max-width: 32rem; }
.page-title-input {
    width: 100%;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--p-text-color);
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 0.25rem 0.5rem;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.page-title-input:hover { border-color: var(--p-content-border-color); }
.page-title-input:focus {
    outline: none;
    background: var(--p-content-background);
    border-color: var(--p-primary-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--p-primary-color) 25%, transparent);
}
.back-link { display: inline-flex; align-items: center; gap: 0.375rem; color: var(--p-text-muted-color); text-decoration: none; font-size: 0.875rem; }
.back-link:hover { color: var(--p-text-color); }
.stack { display: flex; flex-direction: column; gap: 1.5rem; }

.waveform { width: 100%; transition: opacity 0.2s; }
.waveform.is-loading { opacity: 0.4; }

.controls { display: flex; align-items: center; gap: 0.75rem; margin-top: 1.25rem; }
.time { margin-left: auto; font-variant-numeric: tabular-nums; color: var(--p-text-muted-color); font-size: 0.9375rem; }

.processing { display: flex; align-items: center; gap: 1rem; padding: 2rem 0.5rem; color: var(--p-text-muted-color); }
.processing .pi-spinner { font-size: 1.75rem; }
.processing-title { margin: 0; font-weight: 600; color: var(--p-text-color); }
.processing-sub { margin: 0.125rem 0 0; font-size: 0.875rem; }

.mixer-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.mixer-title { font-size: 1rem; font-weight: 600; }
.mixer-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.template-select { min-width: 12rem; }
.template-option { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; width: 100%; }
.template-option-del { color: var(--p-text-muted-color); padding: 0.25rem; border-radius: 4px; }
.template-option-del:hover { color: var(--p-red-500); }
.save-dialog { display: flex; flex-direction: column; gap: 0.5rem; }
.save-dialog label { font-size: 0.875rem; font-weight: 500; }
.save-dialog .p-inputtext { width: 100%; }
.save-dialog-hint { margin: 0.25rem 0 0; font-size: 0.8125rem; color: var(--p-text-muted-color); }
.download-btn { margin-left: auto; }
.share-dialog { display: flex; flex-direction: column; gap: 0.75rem; }
.share-dialog-hint { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
.share-link-row { display: flex; gap: 0.5rem; }
.share-link { flex: 1; font-family: var(--p-font-family-mono, monospace); font-size: 0.8125rem; }
.mixer-status { font-size: 0.8125rem; color: var(--p-text-muted-color); opacity: 0; transition: opacity 0.2s; display: inline-flex; align-items: center; gap: 0.25rem; }
.mixer-status.visible { opacity: 1; }
.mixer { display: flex; flex-wrap: wrap; gap: 1.25rem; padding-top: 0.25rem; }
.fader { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; width: 4.75rem; }
.fader.muted { opacity: 0.65; }
.fader-val { font-size: 0.8125rem; font-variant-numeric: tabular-nums; color: var(--p-text-muted-color); }
.fader-slider { height: 150px; }
.label-field { position: relative; width: 100%; }
.fader-label-input {
    width: 100%;
    text-align: center;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--p-text-color);
    background: var(--p-content-background);
    border: 1px solid var(--p-content-border-color);
    border-radius: 6px;
    padding: 0.3rem 0.4rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.fader-label-input::placeholder { color: var(--p-text-muted-color); font-weight: 500; font-style: italic; }
.fader-label-input:hover { border-color: var(--p-primary-color); }
.fader-label-input:focus {
    outline: none;
    border-color: var(--p-primary-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--p-primary-color) 25%, transparent);
}
.mixer-hint {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin: 0 0 1rem;
    font-size: 0.8125rem;
    color: var(--p-text-muted-color);
}
.pan { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; width: 100%; margin-top: 0.25rem; }
.pan-slider { width: 100%; }
.pan-val { background: none; border: none; padding: 0; cursor: pointer; font-size: 0.75rem; font-variant-numeric: tabular-nums; color: var(--p-text-muted-color); }
.pan-val:hover { color: var(--p-text-color); }

.split-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.split-title { font-size: 1rem; font-weight: 600; }
.split-status { font-size: 0.8125rem; color: var(--p-text-muted-color); display: inline-flex; align-items: center; gap: 0.375rem; }
.split-hint { margin: 0 0 1rem; font-size: 0.875rem; color: var(--p-text-muted-color); }
.split-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1.25rem; margin-bottom: 1rem; }
.split-param label { display: flex; justify-content: space-between; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; }
.split-param-val { color: var(--p-primary-color); font-variant-numeric: tabular-nums; }
.split-param-hint { margin: 0.5rem 0 0; font-size: 0.75rem; color: var(--p-text-muted-color); }
.split-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.split-empty { margin: 0.5rem 0 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
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
.child-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.child-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.375rem 0; border-bottom: 1px solid var(--p-content-border-color); }
.child-row:last-child { border-bottom: none; }
.child-name { flex: 1; color: var(--p-text-color); text-decoration: none; font-weight: 500; }
.child-name:hover { color: var(--p-primary-color); }
.child-time { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }

.meta { margin: 0; display: grid; gap: 0.875rem; }
.meta-row { display: flex; justify-content: space-between; align-items: center; }
.meta-row dt { color: var(--p-text-muted-color); font-size: 0.875rem; }
.meta-row dd { margin: 0; font-weight: 500; }
</style>
