<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Card from 'primevue/card';
import Message from 'primevue/message';
import Slider from 'primevue/slider';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import WaveSurfer from 'wavesurfer.js';

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
const audioReady = ref(false); // <audio> element has buffered enough to play
const currentTime = ref(0);
const loadError = ref(null);

// Floating mini-transport shows when the main controls scroll out of view.
const controlsEl = ref(null);
const showMiniTransport = ref(false);

// Phones get a row-per-channel horizontal fader layout instead of the
// hardware-mixer column-of-vertical-faders that doesn't fit a narrow viewport.
const isNarrow = ref(false);
let narrowMq = null;
const onNarrowChange = (e) => { isNarrow.value = e.matches; };

// Compact-by-default waveform; the user can expand it for fine work. Per-channel
// height switches between the two presets and we push the new value into
// wavesurfer live so the change doesn't require a reload.
const waveformExpanded = ref(false);
const waveformHeight = (channels, expanded) => {
    if (expanded) {
        // Big preset: tall single waveform for mono/stereo, multi-channel split
        // capped around ~440px total with a 22px per-channel floor.
        return channels > 1
            ? Math.max(22, Math.min(64, Math.floor(440 / channels)))
            : 140;
    }
    // Compact preset: roughly the height of two normal channel strips total.
    return channels > 1
        ? Math.max(10, Math.min(22, Math.floor(88 / channels)))
        : 56;
};

// Peak meter levels per channel (0–1, smoothed for fall-off).
const meterLevels = ref([]);
let analysers = [];
let meterRafId = null;

// Local source of truth for the (editable) track name so the heading and the
// document title update immediately on rename, without a full Inertia reload.
const trackName = ref(props.track.name);

// Per-channel mixer state. `levels` is 0–100 (%), `pans` is -100 (L) to 100 (R),
// `muted` toggles each channel, `soloed` solos channels (when any channel is
// soloed, non-soloed channels are silenced), `labels` are user-supplied names.
const levels = ref([]);
const pans = ref([]);
const muted = ref([]);
const soloed = ref([]);
// Per-channel preamp trim in dB (0, 5, 10, 15, 20). Click cycles in 5 dB steps;
// applied before the fader so a quiet recording can be lifted into a range
// where 0–100% of the fader actually does something useful.
const boosts = ref([]);
const BOOST_STEPS = [0, 5, 10, 15, 20];
const BOOST_MAX = 20;
const labels = ref([]);
const mixerUnavailable = ref(false);

// Convert dB to a linear gain multiplier: 0 dB -> 1, +6 dB -> ~2, +20 dB -> 10.
const dbToLinear = (db) => Math.pow(10, (db || 0) / 20);

// Saved channel-name templates the user can apply to this (or any) track.
const templateList = ref([...props.templates]);
const showSaveDialog = ref(false);
const newTemplateName = ref('');

let ws = null;
let audioCtx = null;
let audioEl = null;
let gainNodes = [];
let panners = [];
let pollTimer = null;

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

            // Post-fader peak meter tap. Small FFT — we only need time-domain
            // peak, not a spectrum, so 256 samples is plenty and cheap.
            const analyser = audioCtx.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0;

            splitter.connect(gain, i, 0);
            gain.connect(panner);
            gain.connect(analyser);
            panner.connect(audioCtx.destination);

            gainNodes.push(gain);
            panners.push(panner);
            analysers.push(analyser);
        }

        meterLevels.value = Array.from({ length: channels }, () => 0);

        // Seed from a saved default mix when present (the owner's saved
        // levels/pans/mutes are persisted on the track and apply to shared
        // viewers too). Anything past the saved length falls back to the
        // unity defaults so a re-encoded source with extra channels still loads.
        const saved = props.track.default_mix ?? null;
        levels.value = Array.from({ length: channels }, (_, i) => saved?.[i]?.level ?? 100);
        pans.value = Array.from({ length: channels }, (_, i) => saved?.[i]?.pan ?? 0);
        muted.value = Array.from({ length: channels }, (_, i) => !!saved?.[i]?.muted);
        soloed.value = Array.from({ length: channels }, (_, i) => !!saved?.[i]?.solo);
        boosts.value = Array.from({ length: channels }, (_, i) => Number(saved?.[i]?.boost ?? 0));
        labels.value = Array.from({ length: channels }, (_, i) => props.track.channel_labels?.[i] ?? '');

        // Push the seeded state into the audio graph directly (instantaneous,
        // pre-playback) so the first playback already reflects the saved mix.
        const anySolo = soloed.value.some(Boolean);
        for (let i = 0; i < channels; i++) {
            const audible = (!anySolo || soloed.value[i]) && !muted.value[i];
            const trim = dbToLinear(boosts.value[i]);
            gainNodes[i].gain.value = audible ? (levels.value[i] / 100) * trim : 0;
            panners[i].pan.value = pans.value[i] / 100;
        }
    } catch (e) {
        // A tainted (cross-origin) media source throws here; fall back to plain
        // playback without per-channel control.
        mixerUnavailable.value = true;
    }
};

// A channel plays when (no channel is soloed OR this channel is soloed) AND it
// isn't muted. Solo overrides other channels' levels, not this channel's mute.
const channelAudible = (i) => {
    const anySolo = soloed.value.some(Boolean);
    return (!anySolo || soloed.value[i]) && !muted.value[i];
};

const applyGain = (i) => {
    const node = gainNodes[i];
    if (!node || !audioCtx) return;
    const trim = dbToLinear(boosts.value[i]);
    const value = channelAudible(i) ? (levels.value[i] / 100) * trim : 0;
    node.gain.setTargetAtTime(value, audioCtx.currentTime, 0.015);
};

const adjustBoost = (i, delta) => {
    const next = Math.max(0, Math.min(BOOST_MAX, (boosts.value[i] || 0) + delta));
    if (next === boosts.value[i]) return;
    boosts.value[i] = next;
    applyGain(i);
};

// Flipping any channel's solo changes which other channels are audible, so
// re-apply gain on the whole strip.
const applyAllGains = () => {
    for (let i = 0; i < gainNodes.length; i++) applyGain(i);
};

const toggleMute = (i) => {
    muted.value[i] = !muted.value[i];
    applyGain(i);
};

const toggleSolo = (i) => {
    soloed.value[i] = !soloed.value[i];
    applyAllGains();
};

// Sample peak per channel and fall off ~6× per second. Reading time-domain
// data is essentially free; the cost is the rAF loop, which we only run while
// the track is playing.
const meterBuffers = [];
const startMeters = () => {
    if (meterRafId || !analysers.length) return;
    while (meterBuffers.length < analysers.length) {
        meterBuffers.push(new Float32Array(analysers[meterBuffers.length].fftSize));
    }
    const tick = () => {
        const next = meterLevels.value.slice();
        for (let i = 0; i < analysers.length; i++) {
            analysers[i].getFloatTimeDomainData(meterBuffers[i]);
            let peak = 0;
            const buf = meterBuffers[i];
            for (let j = 0; j < buf.length; j++) {
                const a = Math.abs(buf[j]);
                if (a > peak) peak = a;
            }
            const prev = next[i] ?? 0;
            // Fast attack, slow release for VU-meter feel.
            next[i] = peak > prev ? peak : prev * 0.88;
            if (next[i] < 0.0005) next[i] = 0;
        }
        meterLevels.value = next;
        meterRafId = requestAnimationFrame(tick);
    };
    meterRafId = requestAnimationFrame(tick);
};

const stopMeters = () => {
    if (meterRafId) cancelAnimationFrame(meterRafId);
    meterRafId = null;
    // Let the bars fall to zero so they don't freeze at their last value.
    if (meterLevels.value.length) {
        meterLevels.value = meterLevels.value.map(() => 0);
    }
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
            solo: !!soloed.value[i],
            boost: boosts.value[i] ?? 0,
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

const showCopyMixDialog = ref(false);
const selectedCopyMixId = ref(null);

const confirmCopyMix = () => {
    const source = mixSourceList.value.find((s) => s.id === selectedCopyMixId.value);
    if (source) applyMixSource(source);
    showCopyMixDialog.value = false;
    selectedCopyMixId.value = null;
};

const applyMixSource = (source) => {
    if (!source?.default_mix) return;
    const channels = channelCount();
    for (let i = 0; i < channels; i++) {
        const entry = source.default_mix[i];
        if (!entry) continue;
        levels.value[i] = entry.level ?? 100;
        pans.value[i] = entry.pan ?? 0;
        muted.value[i] = !!entry.muted;
        soloed.value[i] = !!entry.solo;
        boosts.value[i] = Number(entry.boost ?? 0);
        applyPan(i);
    }
    applyAllGains();
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
    saveLabels();
};

const templatesMenu = ref(null);
const defaultMixMenu = ref(null);

const templatesMenuItems = computed(() => {
    const items = templateList.value.map((t) => ({
        label: t.name,
        template: t,
        command: () => applyTemplate(t),
    }));
    if (items.length) items.push({ separator: true });
    items.push({
        label: 'Save current as template…',
        icon: 'pi pi-bookmark',
        command: openSaveTemplate,
    });
    return items;
});

const defaultMixMenuItems = computed(() => {
    const items = [{
        label: hasDefaultMix.value ? 'Update default mix' : 'Save default mix',
        icon: 'pi pi-save',
        command: saveDefaultMix,
    }];
    if (hasDefaultMix.value) {
        items.push({
            label: 'Clear default mix',
            icon: 'pi pi-times',
            command: clearDefaultMix,
        });
    }
    if (mixSourceList.value.length) {
        items.push({ separator: true });
        items.push({
            label: 'Copy mix from another track…',
            icon: 'pi pi-copy',
            command: () => {
                selectedCopyMixId.value = mixSourceList.value[0]?.id ?? null;
                showCopyMixDialog.value = true;
            },
        });
    }
    return items;
});

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

    ws = WaveSurfer.create({
        container: waveformEl.value,
        media: audio,
        peaks: peaksChannels.value ?? undefined,
        duration: props.track.duration_seconds ?? undefined,
        splitChannels: channels > 1 ? Array.from({ length: channels }, () => ({})) : undefined,
        height: waveformHeight(channels, waveformExpanded.value),
        waveColor: cssVar('--p-primary-200', '#c7d2fe'),
        progressColor: cssVar('--p-primary-color', '#6366f1'),
        cursorColor: cssVar('--p-text-color', '#1f2937'),
        cursorWidth: 2,
        barWidth: 2,
        barGap: 1,
        barRadius: 2,
        normalize: false,
    });

    setupMixer(audio, channels, corsOk);

    audioEl = audio;
    audio.addEventListener('canplay', () => { audioReady.value = true; });

    ws.on('ready', () => { isReady.value = true; });
    ws.on('play', () => {
        isPlaying.value = true;
        audioCtx?.resume();
        startMeters();
    });
    ws.on('pause', () => { isPlaying.value = false; stopMeters(); });
    ws.on('finish', () => { isPlaying.value = false; stopMeters(); });
    ws.on('timeupdate', (t) => { currentTime.value = t; });
    ws.on('error', (e) => { loadError.value = e?.message || String(e); });
    ws.on('ready', () => {
        observeControlsVisibility();
    });
};

// Show a floating play/time bar when the main controls scroll off-screen.
let controlsObserver = null;
const observeControlsVisibility = () => {
    if (controlsObserver || !controlsEl.value || typeof IntersectionObserver === 'undefined') return;
    controlsObserver = new IntersectionObserver(([entry]) => {
        showMiniTransport.value = !entry.isIntersecting;
    }, { threshold: 0 });
    controlsObserver.observe(controlsEl.value);
};

const togglePlay = () => ws?.playPause();

const restart = () => {
    ws?.seekTo(0);
    ws?.play();
};

const seekBy = (delta) => {
    if (!ws) return;
    const duration = ws.getDuration() || props.track.duration_seconds || 0;
    if (!duration) return;
    const next = Math.max(0, Math.min(duration, (ws.getCurrentTime() || 0) + delta));
    ws.seekTo(next / duration);
};

// Space/J/K/L/←/→/Home for transport; ignored while typing in inputs so the
// channel-label and region-name fields keep their normal keys.
const onKeyDown = (e) => {
    if (!document.hasFocus()) return;
    const target = e.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;

    switch (e.key) {
        case ' ':
            e.preventDefault();
            togglePlay();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            seekBy(e.shiftKey ? -30 : -5);
            break;
        case 'ArrowRight':
            e.preventDefault();
            seekBy(e.shiftKey ? 30 : 5);
            break;
        case 'j':
        case 'J':
            e.preventDefault();
            seekBy(-10);
            break;
        case 'l':
        case 'L':
            e.preventDefault();
            seekBy(10);
            break;
        case 'k':
        case 'K':
            e.preventDefault();
            togglePlay();
            break;
        case 'Home':
            e.preventDefault();
            restart();
            break;
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    narrowMq = window.matchMedia('(max-width: 640px)');
    isNarrow.value = narrowMq.matches;
    narrowMq.addEventListener('change', onNarrowChange);
    if (props.track.peaks_ready) {
        nextTick(initWaveform);
    } else {
        // Peaks are generated by a queued job; poll until they land.
        pollTimer = setInterval(() => {
            router.reload({ only: ['track'], preserveScroll: true });
        }, 4000);
    }
});

watch(waveformExpanded, (expanded) => {
    if (!ws) return;
    ws.setOptions({ height: waveformHeight(channelCount(), expanded) });
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
    window.removeEventListener('keydown', onKeyDown);
    narrowMq?.removeEventListener('change', onNarrowChange);
    narrowMq = null;
    stopMeters();
    controlsObserver?.disconnect();
    controlsObserver = null;
    clearInterval(pollTimer);
    clearTimeout(savedTimer);
    clearTimeout(mixStatusTimer);
    ws?.destroy();
    ws = null;
    audioEl = null;
    audioCtx?.close();
    audioCtx = null;
    gainNodes = [];
    panners = [];
    analysers = [];
    pans.value = [];
    soloed.value = [];
    boosts.value = [];
    meterLevels.value = [];
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
            <div class="meta-strip">
                <Tag v-if="track.peaks_ready" severity="success" value="Ready" />
                <Tag v-else severity="warn" value="Processing" />
                <span class="meta-chip"><i class="pi pi-sliders-v" /> {{ channelCount() || '—' }} ch</span>
                <span class="meta-chip"><i class="pi pi-clock" /> {{ formatTime(track.duration_seconds) }}</span>
                <span class="meta-chip"><i class="pi pi-file" /> {{ formatBytes(track.size) }}</span>
                <span v-if="track.mime" class="meta-chip">{{ track.mime }}</span>
            </div>

            <Card>
                <template #content>
                    <Message v-if="loadError" severity="error" :closable="false">
                        Couldn't load audio: {{ loadError }}
                    </Message>

                    <template v-if="track.peaks_ready">
                        <div ref="waveformEl" class="waveform" :class="{ 'is-loading': !isReady }" />

                        <div ref="controlsEl" class="controls">
                            <Button
                                :icon="isPlaying ? 'pi pi-pause' : 'pi pi-play'"
                                :label="isPlaying ? 'Pause' : 'Play'"
                                :disabled="!isReady || !audioReady"
                                @click="togglePlay"
                            />
                            <Button
                                icon="pi pi-replay"
                                text
                                rounded
                                :disabled="!isReady || !audioReady"
                                aria-label="Restart"
                                @click="restart"
                            />
                            <span v-if="isReady && !audioReady" class="buffering">
                                <i class="pi pi-spin pi-spinner" /> Buffering…
                            </span>
                            <span class="time">
                                {{ formatTime(currentTime) }} / {{ formatTime(track.duration_seconds) }}
                            </span>
                            <span class="shortcut-hint" aria-hidden="true">Space ⏯ · ← → seek · J/L ±10s</span>
                            <Button
                                :icon="waveformExpanded ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                                :label="waveformExpanded ? 'Collapse' : 'Expand'"
                                text
                                size="small"
                                severity="secondary"
                                class="expand-toggle"
                                :aria-label="waveformExpanded ? 'Collapse waveform' : 'Expand waveform'"
                                :aria-pressed="waveformExpanded"
                                @click="waveformExpanded = !waveformExpanded"
                            />
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
                            <Tag v-if="hasDefaultMix" value="Default mix saved" severity="success" class="mix-tag" />
                            <Button
                                label="Channel names"
                                icon="pi pi-tag"
                                size="small"
                                outlined
                                aria-haspopup="true"
                                aria-controls="templates-menu"
                                @click="templatesMenu.toggle($event)"
                            />
                            <Menu
                                id="templates-menu"
                                ref="templatesMenu"
                                :model="templatesMenuItems"
                                popup
                            >
                                <template #item="{ item, props: itemProps }">
                                    <a v-if="item.template" v-bind="itemProps.action" class="menu-template-row">
                                        <span class="menu-template-name">{{ item.label }}</span>
                                        <i
                                            class="pi pi-trash menu-template-del"
                                            :aria-label="`Delete template ${item.label}`"
                                            @click.stop.prevent="deleteTemplate(item.template)"
                                        />
                                    </a>
                                </template>
                            </Menu>
                            <Button
                                label="Default mix"
                                icon="pi pi-sliders-h"
                                size="small"
                                outlined
                                aria-haspopup="true"
                                aria-controls="defaultmix-menu"
                                @click="defaultMixMenu.toggle($event)"
                            />
                            <Menu
                                id="defaultmix-menu"
                                ref="defaultMixMenu"
                                :model="defaultMixMenuItems"
                                popup
                            />
                        </div>
                    </div>
                </template>
                <template #content>
                    <div class="mixer">
                        <div
                            v-for="(lvl, i) in levels"
                            :key="i"
                            class="fader"
                            :class="{
                                muted: muted[i],
                                silenced: soloed.some(Boolean) && !soloed[i] && !muted[i],
                                'is-horizontal': isNarrow,
                            }"
                        >
                            <span class="fader-val">{{ muted[i] ? 'muted' : `${lvl}%` }}</span>
                            <div class="trim" :class="{ active: (boosts[i] || 0) > 0 }">
                                <button
                                    type="button"
                                    class="trim-step"
                                    :disabled="(boosts[i] || 0) <= 0"
                                    :aria-label="`Decrease trim for ${channelLabel(i, levels.length)}`"
                                    @click="adjustBoost(i, -5)"
                                >−</button>
                                <span
                                    class="trim-val"
                                    :title="`Preamp trim: +${boosts[i] || 0} dB`"
                                >+{{ boosts[i] || 0 }}</span>
                                <button
                                    type="button"
                                    class="trim-step"
                                    :disabled="(boosts[i] || 0) >= BOOST_MAX"
                                    :aria-label="`Increase trim for ${channelLabel(i, levels.length)}`"
                                    @click="adjustBoost(i, 5)"
                                >+</button>
                            </div>
                            <div class="fader-stack">
                                <div class="meter" :aria-hidden="true">
                                    <div class="meter-fill" :style="{ height: ((meterLevels[i] || 0) * 100) + '%' }" />
                                </div>
                                <div class="fader-slot" :aria-hidden="true">
                                    <Slider
                                        v-model="levels[i]"
                                        :orientation="isNarrow ? 'horizontal' : 'vertical'"
                                        :min="0"
                                        :max="100"
                                        :disabled="muted[i]"
                                        class="fader-slider"
                                        @update:model-value="applyGain(i)"
                                    />
                                </div>
                            </div>
                            <div class="ms-buttons">
                                <button
                                    type="button"
                                    class="ms-btn ms-mute"
                                    :class="{ active: muted[i] }"
                                    :aria-label="`Mute ${channelLabel(i, levels.length)}`"
                                    :aria-pressed="muted[i]"
                                    @click="toggleMute(i)"
                                >M</button>
                                <button
                                    type="button"
                                    class="ms-btn ms-solo"
                                    :class="{ active: soloed[i] }"
                                    :aria-label="`Solo ${channelLabel(i, levels.length)}`"
                                    :aria-pressed="soloed[i]"
                                    @click="toggleSolo(i)"
                                >S</button>
                            </div>
                            <div v-if="canEdit" class="label-field">
                                <input
                                    :ref="(el) => setLabelRef(el, i)"
                                    v-model="labels[i]"
                                    class="fader-label-input"
                                    :placeholder="channelLabel(i, levels.length)"
                                    maxlength="60"
                                    :aria-label="`Label for ${channelLabel(i, levels.length)}`"
                                    :title="`Rename ${channelLabel(i, levels.length)}`"
                                    @blur="saveLabels"
                                    @keyup.enter="$event.target.blur()"
                                    @keydown.tab="focusAdjacentLabel($event, i)"
                                />
                            </div>
                            <span v-else class="fader-label">{{ labels[i] || channelLabel(i, levels.length) }}</span>

                            <div class="pan" :title="`Pan ${channelLabel(i, levels.length)} — double-click to center`">
                                <span class="pan-end">L</span>
                                <div class="pan-track" @dblclick="resetPan(i)">
                                    <Slider
                                        v-model="pans[i]"
                                        :min="-100"
                                        :max="100"
                                        class="pan-slider"
                                        :aria-label="`Pan ${channelLabel(i, levels.length)}`"
                                        @update:model-value="applyPan(i)"
                                    />
                                </div>
                                <span class="pan-end">R</span>
                                <span class="pan-val">{{ panLabel(pans[i]) }}</span>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <Message v-else-if="track.peaks_ready && mixerUnavailable" severity="warn" :closable="false">
                Per-channel faders aren't available for this audio source (the browser couldn't access its channels).
            </Message>

        </div>

        <Transition name="mini-fade">
            <div v-if="showMiniTransport && track.peaks_ready" class="mini-transport" role="region" aria-label="Floating transport">
                <Button
                    :icon="isPlaying ? 'pi pi-pause' : 'pi pi-play'"
                    rounded
                    :disabled="!isReady || !audioReady"
                    :aria-label="isPlaying ? 'Pause' : 'Play'"
                    @click="togglePlay"
                />
                <span class="mini-time">
                    {{ formatTime(currentTime) }} / {{ formatTime(track.duration_seconds) }}
                </span>
                <span class="mini-title">{{ trackName }}</span>
            </div>
        </Transition>

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

        <Dialog v-if="canEdit" v-model:visible="showCopyMixDialog" modal header="Copy mix from another track" :style="{ width: '26rem' }">
            <div class="copy-mix-dialog">
                <p class="copy-mix-hint">Choose a track whose saved default mix you'd like to load onto these faders.</p>
                <div class="copy-mix-list">
                    <label v-for="src in mixSourceList" :key="src.id" class="copy-mix-row">
                        <input type="radio" :value="src.id" v-model="selectedCopyMixId" />
                        <span>{{ src.name }}</span>
                    </label>
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showCopyMixDialog = false" />
                <Button label="Apply" icon="pi pi-copy" :disabled="!selectedCopyMixId" @click="confirmCopyMix" />
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
                    <a :href="shareUrl" target="_blank" rel="noopener" class="share-open-link">
                        <i class="pi pi-external-link" /> Open public page
                    </a>
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

.controls { display: flex; align-items: center; gap: 0.75rem; margin-top: 1.25rem; flex-wrap: wrap; }
.time {
    margin-left: auto;
    font-variant-numeric: tabular-nums;
    color: var(--p-text-muted-color);
    font-size: 0.9375rem;
    white-space: nowrap;
}

.processing { display: flex; align-items: center; gap: 1rem; padding: 2rem 0.5rem; color: var(--p-text-muted-color); }
.processing .pi-spinner { font-size: 1.75rem; }
.processing-title { margin: 0; font-weight: 600; color: var(--p-text-color); }
.processing-sub { margin: 0.125rem 0 0; font-size: 0.875rem; }

.mixer-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.mixer-title { font-size: 1rem; font-weight: 600; }
.mixer-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.mix-tag { font-weight: 500; }
.menu-template-row {
    display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; width: 100%;
    padding: 0.5rem 0.75rem; cursor: pointer; color: inherit; text-decoration: none;
}
.menu-template-row:hover { background: var(--p-content-hover-background, rgba(0,0,0,0.04)); }
.menu-template-name { flex: 1; }
.menu-template-del { color: var(--p-text-muted-color); padding: 0.25rem; border-radius: 4px; }
.menu-template-del:hover { color: var(--p-red-500); }
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
.mixer { display: flex; flex-wrap: wrap; gap: 1rem; padding-top: 0.25rem; }
.fader { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; width: 5rem; }
.fader.muted { opacity: 0.65; }
.fader.silenced { opacity: 0.45; }
.fader-val {
    font-size: 0.75rem; font-variant-numeric: tabular-nums; color: var(--p-text-muted-color);
    height: 1rem; visibility: hidden;
}
.fader:hover .fader-val, .fader:focus-within .fader-val { visibility: visible; }
.fader.muted .fader-val { visibility: visible; color: var(--p-red-500); }

/* Per-channel preamp trim. Sits at the top of the channel strip like the GAIN
   knob on a hardware mixer. Reads "+0" when unboosted (subtle); turns amber
   when active to make it obvious a channel is being lifted. */
.trim {
    display: inline-grid;
    grid-template-columns: 1.25rem auto 1.25rem;
    align-items: stretch;
    border: 1px solid var(--p-content-border-color);
    border-radius: 5px;
    overflow: hidden;
    background: var(--p-content-background);
    transition: background 0.12s, border-color 0.12s, box-shadow 0.12s;
    /* Breathing room before the fader slot below — keep the preamp visually
       distinct from the fader as on a hardware channel strip. */
    margin-bottom: 0.5rem;
}
.trim.active {
    background: #fde68a;
    border-color: #b45309;
    box-shadow: 0 0 6px rgba(245, 158, 11, 0.45);
}
.trim-step {
    appearance: none;
    background: transparent;
    border: 0;
    color: var(--p-text-color);
    font-size: 0.8125rem;
    font-weight: 700;
    line-height: 1;
    padding: 0.2rem 0;
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
}
.trim-step:hover:not(:disabled) {
    background: color-mix(in srgb, var(--p-primary-color) 18%, transparent);
    color: var(--p-primary-color);
}
.trim-step:disabled {
    color: var(--p-text-muted-color);
    opacity: 0.4;
    cursor: default;
}
.trim.active .trim-step { color: #1f2937; }
.trim.active .trim-step:hover:not(:disabled) {
    background: rgba(0, 0, 0, 0.08);
    color: #1f2937;
}
.trim-val {
    font-size: 0.6875rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--p-text-muted-color);
    padding: 0 0.35rem;
    align-self: center;
    line-height: 1;
    user-select: none;
}
.trim.active .trim-val { color: #1f2937; }

.fader-stack {
    display: flex; align-items: stretch; justify-content: center; gap: 12px; height: 160px;
}
.meter {
    width: 6px; position: relative;
    background: #0b0f17;
    border: 1px solid color-mix(in srgb, #000 35%, var(--p-content-border-color));
    border-radius: 3px; overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.45), inset 0 -1px 0 rgba(255, 255, 255, 0.04);
}
.meter-fill {
    position: absolute; left: 0; right: 0; bottom: 0;
    /* Paint the gradient at the full meter height so the colors live at fixed
       absolute positions (green low, yellow upper, red at the top). The fill's
       own height clips it from the bottom, so a quiet signal stays green and
       only loud peaks reach into yellow/red. */
    background: linear-gradient(to top, #22c55e 0%, #22c55e 65%, #eab308 82%, #ef4444 100%);
    background-size: 100% 160px;
    background-position: left bottom;
    background-repeat: no-repeat;
    transition: height 60ms linear;
}

/* Recessed fader slot with tick marks down each side, sized to give the cap
   room without cropping at the travel ends. */
.fader-slot {
    position: relative;
    width: 38px;
    display: flex; justify-content: center;
}
/* The slot itself — a dark vertical groove behind the slider track. */
.fader-slot::before {
    content: '';
    position: absolute; top: 10px; bottom: 10px;
    left: 50%; transform: translateX(-50%);
    width: 6px;
    background: linear-gradient(to right, #0a0d13 0%, #1a1f2b 50%, #0a0d13 100%);
    border-radius: 3px;
    box-shadow:
        inset 0 1px 2px rgba(0, 0, 0, 0.55),
        inset 0 -1px 0 rgba(255, 255, 255, 0.06);
    pointer-events: none;
    z-index: 0;
}
/* Tick marks flanking the slot, evenly spaced over the travel range. */
.fader-slot::after {
    content: '';
    position: absolute; top: 10px; bottom: 10px;
    left: 0; right: 0;
    background-image:
        repeating-linear-gradient(
            to bottom,
            var(--p-text-muted-color) 0 1px,
            transparent 1px calc((100% - 1px) / 10)
        ),
        repeating-linear-gradient(
            to bottom,
            var(--p-text-muted-color) 0 1px,
            transparent 1px calc((100% - 1px) / 10)
        );
    background-position: left center, right center;
    background-size: 6px 100%, 6px 100%;
    background-repeat: no-repeat;
    opacity: 0.45;
    pointer-events: none;
    z-index: 0;
}

/* Strip PrimeVue's default track look so our groove shows; keep the slider
   itself functional and clickable across its full width. */
.fader-slider {
    height: 100%;
    position: relative;
    z-index: 1;
}
.fader-slider :deep(.p-slider) {
    width: 14px !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}
.fader-slider :deep(.p-slider-range) {
    /* Vertical slider: PrimeVue inline-sets height to value%; we only set the
       thickness and horizontal centering. width is plain (no !important) so the
       horizontal-mode inline width can win in the mobile rule. */
    width: 6px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(to top,
        color-mix(in srgb, var(--p-primary-color) 55%, #000),
        var(--p-primary-color)) !important;
    border-radius: 3px;
    box-shadow: 0 0 6px color-mix(in srgb, var(--p-primary-color) 40%, transparent);
}
/* The fader cap — rectangular, beveled, with a center indent line. */
.fader-slider :deep(.p-slider-handle) {
    width: 30px !important;
    height: 18px !important;
    /* No left override: PrimeVue sets `left: 50%` inline on the vertical
       handle, and `left: <value%>` inline on the horizontal handle. An
       !important rule here would beat the inline style and freeze the
       horizontal cap at center. margin-left handles centering for both. */
    margin-left: -15px !important;
    border-radius: 3px !important;
    background: linear-gradient(to bottom,
        #fafbfc 0%,
        #e4e7ec 45%,
        #c5cad2 52%,
        #e9ecf0 60%,
        #b8bec7 100%) !important;
    border: 1px solid #8a93a0 !important;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.85),
        inset 0 -1px 0 rgba(0, 0, 0, 0.18),
        0 1px 2px rgba(0, 0, 0, 0.35),
        0 3px 6px rgba(0, 0, 0, 0.2) !important;
    transition: filter 0.12s, box-shadow 0.12s;
}
/* Colored indicator stripe across the cap — the visual "pointer" of the
   fader's position, like the painted line on a hardware fader. */
.fader-slider :deep(.p-slider-handle)::before {
    content: '' !important;
    position: absolute !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    top: 50% !important;
    height: 4px !important;
    background: linear-gradient(to bottom,
        #b45309 0%,
        #f59e0b 50%,
        #fcd34d 100%) !important;
    transform: translateY(-50%) !important;
    border-radius: 1px !important;
    box-shadow:
        0 0 4px rgba(245, 158, 11, 0.6),
        inset 0 -1px 0 rgba(0, 0, 0, 0.25) !important;
    pointer-events: none;
}
.fader-slider :deep(.p-slider-handle):hover,
.fader-slider :deep(.p-slider-handle):focus {
    filter: brightness(1.12);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.22),
        inset 0 -1px 0 rgba(0, 0, 0, 0.6),
        0 1px 2px rgba(0, 0, 0, 0.5),
        0 4px 8px rgba(0, 0, 0, 0.35),
        0 0 0 2px color-mix(in srgb, var(--p-primary-color) 35%, transparent) !important;
    outline: none !important;
}
.fader.muted .fader-slider :deep(.p-slider-handle) {
    filter: saturate(0.4) brightness(0.85);
}

.ms-buttons { display: flex; gap: 0.25rem; }
.ms-btn {
    appearance: none; border: 1px solid var(--p-content-border-color); background: transparent;
    width: 1.625rem; height: 1.625rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700;
    color: var(--p-text-muted-color); cursor: pointer; padding: 0;
    transition: background 0.12s, color 0.12s, border-color 0.12s;
    font-variant-numeric: tabular-nums; line-height: 1;
}
.ms-btn:hover { color: var(--p-text-color); border-color: var(--p-primary-300, #a5b4fc); }
.ms-btn.ms-mute.active {
    background: var(--p-red-500, #ef4444); border-color: var(--p-red-500, #ef4444); color: white;
}
.ms-btn.ms-solo.active {
    background: var(--p-yellow-500, #eab308); border-color: var(--p-yellow-500, #eab308); color: #1f2937;
}
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
.pan {
    display: grid; grid-template-columns: auto 1fr auto; align-items: center;
    gap: 0.25rem; width: 100%; margin-top: 0.125rem; position: relative;
}
.pan-end { font-size: 0.625rem; color: var(--p-text-muted-color); font-weight: 600; line-height: 1; }
.pan-track {
    position: relative;
}
/* Center tick under the slider — purely visual reference for the resting position. */
.pan-track::before {
    content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    width: 1px; height: 8px; background: var(--p-text-muted-color); opacity: 0.4; z-index: 0;
    pointer-events: none;
}
.pan-slider { width: 100%; position: relative; z-index: 1; }
.pan-val {
    grid-column: 1 / -1; text-align: center; font-size: 0.6875rem;
    font-variant-numeric: tabular-nums; color: var(--p-text-muted-color);
    height: 0.875rem; visibility: hidden;
}
.fader:hover .pan-val, .fader:focus-within .pan-val { visibility: visible; }

.meta-strip {
    display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
    font-size: 0.8125rem; color: var(--p-text-muted-color);
}
.meta-chip {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.125rem 0.5rem; border-radius: 999px;
    background: var(--p-content-background); border: 1px solid var(--p-content-border-color);
    font-variant-numeric: tabular-nums;
}
.meta-chip .pi { font-size: 0.75rem; }

.buffering {
    font-size: 0.8125rem; color: var(--p-text-muted-color);
    display: inline-flex; align-items: center; gap: 0.375rem;
}
.shortcut-hint {
    font-size: 0.75rem; color: var(--p-text-muted-color); opacity: 0.75;
    margin-left: 0.5rem;
    white-space: nowrap;
}
/* Keyboard hints are useless on touch — hide them so the controls row breathes. */
@media (max-width: 640px) {
    .shortcut-hint { display: none; }
    .time { margin-left: 0; }
    /* Collapse the expand toggle to its chevron so the row stays on one line. */
    .expand-toggle :deep(.p-button-label) { display: none; }
    .expand-toggle :deep(.p-button-icon) { margin: 0; }
    /* Metadata chips (channels/duration/size/mime) are nice-to-have, not
       essential — drop them on small screens to give the waveform room. */
    .meta-strip { display: none; }

    /* Horizontal per-channel fader rows. Vertical hardware-mixer faders work
       well on desktop but crowd the narrow viewport; on mobile each channel
       becomes a two-row block: a header row with label, value, and M/S, with
       the full-width volume slider underneath where pan used to live. */
    .mixer { flex-direction: column; flex-wrap: nowrap; gap: 0.75rem; }
    .fader.is-horizontal {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem 0.625rem;
        width: 100%;
        padding-bottom: 0.25rem;
        border-bottom: 1px solid var(--p-content-border-color);
    }
    .fader.is-horizontal:last-child { border-bottom: none; padding-bottom: 0; }
    .fader.is-horizontal .fader-val {
        order: 2; margin-left: auto; min-width: 2.75rem; text-align: right; flex-shrink: 0;
        height: auto; visibility: visible;
    }
    /* Slider drops to its own full-width sub-row — where the pan controls used
       to render — so it has the whole channel width to travel across. */
    .fader.is-horizontal .fader-stack {
        order: 10; flex: 0 0 100%; height: auto; gap: 0.5rem; align-items: center;
    }
    /* Drop the vertical-only meter; live levels are still visible on the value
       readout and on the waveform itself. */
    .fader.is-horizontal .meter { display: none; }
    .fader.is-horizontal .fader-slot {
        width: auto; flex: 1; min-width: 0; height: 24px;
    }
    /* Horizontal groove + ticks: rotate the vertical slot decoration 90°. */
    .fader.is-horizontal .fader-slot::before {
        top: 50%; bottom: auto; left: 10px; right: 10px;
        width: auto; height: 6px;
        transform: translateY(-50%);
        background: linear-gradient(to bottom, #0a0d13 0%, #1a1f2b 50%, #0a0d13 100%);
    }
    .fader.is-horizontal .fader-slot::after {
        top: 0; bottom: 0; left: 10px; right: 10px;
        background-image:
            repeating-linear-gradient(
                to right,
                var(--p-text-muted-color) 0 1px,
                transparent 1px calc((100% - 1px) / 10)
            ),
            repeating-linear-gradient(
                to right,
                var(--p-text-muted-color) 0 1px,
                transparent 1px calc((100% - 1px) / 10)
            );
        background-position: center top, center bottom;
        background-size: 100% 6px, 100% 6px;
    }
    .fader.is-horizontal .fader-slider { width: 100%; height: 100%; }
    /* Reset the vertical base rule's `width: 14px` — for horizontal the slider
       container must span the row so PrimeVue's inline `width: <value%>` on the
       range fill is proportional to the slot, not a 14px stub. */
    .fader.is-horizontal .fader-slider :deep(.p-slider) {
        height: 14px !important; width: 100% !important;
        min-width: 0 !important;
    }
    /* Hide the colored range fill on horizontal — the cap on its slot already
       communicates position, and the fill kept misaligning with the cap. */
    .fader.is-horizontal .fader-slider :deep(.p-slider-range) {
        display: none !important;
    }
    /* Cap rotated 90° for horizontal travel — same look, sliding sideways.
       margin-left centers the cap on PrimeVue's inline `left: <value%>`. */
    .fader.is-horizontal .fader-slider :deep(.p-slider-handle) {
        width: 18px !important; height: 30px !important;
        margin-left: -9px !important; margin-top: -15px !important;
        background: linear-gradient(to right,
            #fafbfc 0%,
            #e4e7ec 45%,
            #c5cad2 52%,
            #e9ecf0 60%,
            #b8bec7 100%) !important;
    }
    .fader.is-horizontal .fader-slider :deep(.p-slider-handle)::before {
        left: 50% !important; right: auto !important;
        top: 0 !important; bottom: 0 !important;
        width: 4px !important; height: auto !important;
        transform: translateX(-50%) !important;
        background: linear-gradient(to right,
            #b45309 0%,
            #f59e0b 50%,
            #fcd34d 100%) !important;
    }
    .fader.is-horizontal .ms-buttons { order: 3; flex-shrink: 0; }
    .fader.is-horizontal .label-field { order: 1; flex: 1; min-width: 0; }
    .fader.is-horizontal .fader-label { order: 1; flex: 1; min-width: 0; }
    /* Pan is rarely needed on a phone and crowds the row — drop it entirely.
       Saved values are preserved server-side and reappear on a wider screen. */
    .fader.is-horizontal .pan { display: none; }
    /* Same for the preamp trim — desktop-only control. */
    .fader.is-horizontal .trim { display: none; }
}

.mini-transport {
    position: fixed; left: 50%; bottom: 1rem; transform: translateX(-50%);
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.5rem 0.875rem;
    background: var(--p-content-background); border: 1px solid var(--p-content-border-color);
    border-radius: 999px; box-shadow: 0 8px 24px rgba(0,0,0,0.18);
    z-index: 50; max-width: calc(100vw - 2rem);
}
.mini-time { font-size: 0.875rem; font-variant-numeric: tabular-nums; color: var(--p-text-color); }
.mini-title {
    font-size: 0.8125rem; color: var(--p-text-muted-color);
    max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.mini-fade-enter-active, .mini-fade-leave-active { transition: opacity 0.15s, transform 0.15s; }
.mini-fade-enter-from, .mini-fade-leave-to { opacity: 0; transform: translate(-50%, 0.5rem); }

.share-open-link {
    display: inline-flex; align-items: center; gap: 0.375rem;
    font-size: 0.8125rem; color: var(--p-primary-color); text-decoration: none;
}
.share-open-link:hover { text-decoration: underline; }

.copy-mix-dialog { display: flex; flex-direction: column; gap: 0.75rem; }
.copy-mix-hint { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }
.copy-mix-list { display: flex; flex-direction: column; gap: 0.25rem; max-height: 18rem; overflow: auto; }
.copy-mix-row {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.625rem;
    border-radius: 6px; cursor: pointer;
}
.copy-mix-row:hover { background: var(--p-content-hover-background, rgba(0,0,0,0.04)); }
</style>
