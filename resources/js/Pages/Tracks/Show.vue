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

const props = defineProps({
    track: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
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

const channelCount = () => props.track.peaks?.channels?.length ?? 0;

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

        levels.value = Array(channels).fill(100);
        pans.value = Array(channels).fill(0);
        muted.value = Array(channels).fill(false);
        labels.value = Array.from({ length: channels }, (_, i) => props.track.channel_labels?.[i] ?? '');
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

let initStarted = false;

const initWaveform = async () => {
    if (initStarted || !waveformEl.value) return;
    initStarted = true; // guard the await window against a second trigger

    const channels = channelCount();

    // Per-channel mixing and crossorigin='anonymous' both need to read the
    // stream's bytes, which for an off-origin URL hinges on the bucket's CORS.
    // Probe once: if reachable, load CORS-clean and build the mixer; if not,
    // fall back to a plain element so playback stays audible (sans faders)
    // rather than failing to load or muting silently.
    const corsOk = await streamReachable();

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
        peaks: props.track.peaks?.channels ?? undefined,
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
    });

    setupMixer(audio, channels, corsOk);

    ws.on('ready', () => { isReady.value = true; });
    ws.on('play', () => { isPlaying.value = true; audioCtx?.resume(); });
    ws.on('pause', () => { isPlaying.value = false; });
    ws.on('finish', () => { isPlaying.value = false; });
    ws.on('timeupdate', (t) => { currentTime.value = t; });
    ws.on('error', (e) => { loadError.value = e?.message || String(e); });
};

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
    clearTimeout(savedTimer);
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
                    <i class="pi pi-pencil title-edit-icon" />
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
                            <span class="mixer-status" :class="{ visible: labelsStatus }">
                                <template v-if="labelsStatus === 'saving'">Saving…</template>
                                <template v-else-if="labelsStatus === 'saved'"><i class="pi pi-check" /> Saved</template>
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
                        </div>
                    </div>
                </template>
                <template #content>
                    <p v-if="canEdit" class="mixer-hint"><i class="pi pi-pencil" /> Click a channel name to rename it</p>
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
                                <i class="pi pi-pencil label-edit-icon" />
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
    padding: 0.25rem 1.75rem 0.25rem 0.5rem;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.page-title-input:hover { border-color: var(--p-content-border-color); }
.page-title-input:focus {
    outline: none;
    background: var(--p-content-background);
    border-color: var(--p-primary-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--p-primary-color) 25%, transparent);
}
.title-edit-icon {
    position: absolute;
    right: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.75rem;
    color: var(--p-text-muted-color);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s;
}
.title-field:hover .title-edit-icon,
.page-title-input:focus + .title-edit-icon { opacity: 1; }
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
    padding: 0.3rem 1rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.fader-label-input::placeholder { color: var(--p-text-muted-color); font-weight: 500; font-style: italic; }
.fader-label-input:hover { border-color: var(--p-primary-color); }
.fader-label-input:focus {
    outline: none;
    border-color: var(--p-primary-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--p-primary-color) 25%, transparent);
}
.label-edit-icon {
    position: absolute;
    right: 0.4rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.6875rem;
    color: var(--p-text-muted-color);
    pointer-events: none;
    transition: color 0.15s;
}
.fader-label-input:focus + .label-edit-icon { color: var(--p-primary-color); }
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

.meta { margin: 0; display: grid; gap: 0.875rem; }
.meta-row { display: flex; justify-content: space-between; align-items: center; }
.meta-row dt { color: var(--p-text-muted-color); font-size: 0.875rem; }
.meta-row dd { margin: 0; font-weight: 500; }
</style>
