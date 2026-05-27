<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import MultiSelect from 'primevue/multiselect';
import ProgressBar from 'primevue/progressbar';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import { typeLabel, typeOptions } from '@/lib/eventTypes';
import { useS3Upload, apiFetch } from '@/composables/useS3Upload';
import { useSplitBeforeUpload } from '@/composables/useSplitBeforeUpload.js';
import { useStitchedSplit } from '@/composables/useStitchedSplit.js';
import SplitBeforeUploadDialog from '@/Components/SplitBeforeUploadDialog.vue';
import StitchedSplitDialog from '@/Components/StitchedSplitDialog.vue';

const props = defineProps({
    event: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    assignableTracks: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: true },
});

// Owners get the app chrome; public share links render under a guest layout.
const Layout = props.canEdit ? AuthenticatedLayout : PublicLayout;
// Toast is available to public viewers too so upload validation errors surface
// during share-link contributions. Confirm dialogs stay owner-only — public
// viewers don't have destructive actions.
const toast = useToast();
const confirm = props.canEdit ? useConfirm() : null;

const options = computed(() => typeOptions(props.types));
const tracks = computed(() => props.event.tracks ?? []);
const media = computed(() => props.event.media ?? []);

const refresh = () => router.reload({ only: ['event', 'assignableTracks'], preserveScroll: true });

const formatDate = (iso) => iso
    ? new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
    : null;

const formatBytes = (n) => {
    if (n == null) return '';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
};

// mm:ss for a track's playable length; null when peaks haven't been extracted yet.
const formatDuration = (s) => {
    if (s == null) return null;
    const m = Math.floor(s / 60);
    const sec = Math.round(s % 60);
    return `${m}:${String(sec).padStart(2, '0')}`;
};

// --- Editing the event details -----------------------------------------------
const showEdit = ref(false);
const editForm = useForm({
    name: props.event.name,
    type: props.event.type,
    event_date: props.event.event_date ? new Date(props.event.event_date + 'T00:00:00') : null,
    location: props.event.location ?? '',
    description: props.event.description ?? '',
});

const toDateString = (d) => {
    if (!d) return null;
    const date = d instanceof Date ? d : new Date(d);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const submitEdit = () => {
    editForm
        .transform((data) => ({ ...data, event_date: toDateString(data.event_date) }))
        .patch(route('events.update', props.event.id), {
            preserveScroll: true,
            onSuccess: () => { showEdit.value = false; },
        });
};

const confirmDeleteEvent = () => confirm.require({
    message: `Delete "${props.event.name}"? Its tracks and media stay in your library; only the event is removed.`,
    header: 'Delete event',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => router.delete(route('events.destroy', props.event.id)),
});

// --- Sharing -----------------------------------------------------------------
const shareUrl = ref(props.event.share_url);

const share = async () => {
    if (shareUrl.value) return copyShare();
    const res = await apiFetch(route('events.share', props.event.id), { method: 'POST' });
    if (!res.ok) return toast.add({ severity: 'error', summary: 'Share failed', life: 4000 });
    shareUrl.value = (await res.json()).share_url;
    copyShare();
};

const copyShare = () => {
    navigator.clipboard?.writeText(shareUrl.value);
    toast.add({ severity: 'success', summary: 'Link copied', detail: shareUrl.value, life: 3000 });
};

const unshare = async () => {
    const res = await apiFetch(route('events.unshare', props.event.id), { method: 'DELETE' });
    if (res.ok) { shareUrl.value = null; toast.add({ severity: 'info', summary: 'Sharing disabled', life: 2500 }); }
};

// --- Assigning tracks --------------------------------------------------------
const showAddTracks = ref(false);
const selectedTrackIds = ref([]);

const submitAddTracks = () => {
    if (!selectedTrackIds.value.length) return;
    router.post(route('events.tracks.attach', props.event.id), { track_ids: selectedTrackIds.value }, {
        preserveScroll: true,
        onSuccess: () => { showAddTracks.value = false; selectedTrackIds.value = []; },
    });
};

const removeTrack = (track) => router.delete(route('events.tracks.detach', [props.event.id, track.id]), { preserveScroll: true });

// --- Media uploads -----------------------------------------------------------
const ALLOWED = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
];
const mediaInput = ref(null);

// Upload routes come from the server so the same component handles owner and
// public-share uploads — they hit different endpoints scoped to either the
// user or the event's share token.
const mediaUploadRoutes = computed(() => props.event.media_upload_routes ?? null);
const canUploadMedia = computed(() => !!mediaUploadRoutes.value);

const { uploads, addFiles } = useS3Upload({
    routes: mediaUploadRoutes.value ?? {},
    initBody: (file) => ({ filename: file.name, size: file.size, content_type: file.type }),
    validate: (file) => ALLOWED.includes(file.type) ? null : 'unsupported file type',
    finalize: (file, key) => new Promise((resolve, reject) => {
        const body = {
            s3_key: key,
            original_name: file.name,
            mime: file.type,
            size: file.size,
        };
        // The owner endpoint needs event_id in the body (it's not in the URL);
        // the share endpoint takes the event from its route binding instead.
        if (props.canEdit) body.event_id = props.event.id;
        router.post(mediaUploadRoutes.value.store, body, {
            preserveScroll: true, preserveState: true, onSuccess: resolve, onError: reject,
        });
    }),
    onUploaded: (file) => toast?.add({ severity: 'success', summary: 'Uploaded', detail: file.name, life: 3000 }),
    onError: (file, message) => toast?.add({ severity: 'error', summary: 'Upload failed', detail: `${file?.name}: ${message}`, life: 5000 }),
});

const pickMedia = () => mediaInput.value?.click();
const onMediaSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    addFiles(files);
};

// --- Track uploads (audio, straight into this event) -------------------------
const trackInput = ref(null);

const { uploads: trackUploads, addFiles: addTrackFiles } = useS3Upload({
    routes: {
        uploadUrl: route('tracks.upload-url'),
        multipartCreate: route('tracks.multipart.create'),
        multipartSign: route('tracks.multipart.sign'),
        multipartComplete: route('tracks.multipart.complete'),
        multipartAbort: route('tracks.multipart.abort'),
        cleanup: route('tracks.cleanup'),
    },
    // .wav may arrive with an empty MIME type; the server allowlist needs one.
    initBody: (file) => ({ filename: file.name, size: file.size, content_type: file.type || 'audio/wav' }),
    validate: (file) => /\.wav$/i.test(file.name) ? null : 'only .wav files',
    finalize: (file, key) => new Promise((resolve, reject) => {
        router.post(route('tracks.store'), {
            s3_key: key,
            original_name: file.name,
            mime: file.type || 'audio/wav',
            size: file.size,
            event_id: props.event.id,
        }, { preserveScroll: true, preserveState: true, onSuccess: resolve, onError: reject });
    }),
    onUploaded: (file) => toast?.add({ severity: 'success', summary: 'Uploaded', detail: file.name, life: 3000 }),
    onError: (file, message) => toast?.add({ severity: 'error', summary: 'Upload failed', detail: `${file?.name}: ${message}`, life: 5000 }),
});

// Long WAVs get cut up in the browser before upload (see useSplitBeforeUpload).
// The composable hands committed segments back to the same addTrackFiles path
// so the storage/finalise plumbing is shared.
const {
    splitDialogVisible: trackSplitDialogVisible,
    pendingSplitFile: pendingTrackSplitFile,
    enqueueWithSplit: enqueueTrackWithSplit,
    onSplitCommit: onTrackSplitCommit,
    onSplitUploadWhole: onTrackSplitUploadWhole,
    onSplitCancel: onTrackSplitCancel,
} = useSplitBeforeUpload((file) => addTrackFiles([file]));

const pickTrack = () => trackInput.value?.click();
const onTrackSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    for (const file of files) enqueueTrackWithSplit(file);
};

// Multi-file recording flow: stitch the mixer's 4 GB chunks into one virtual
// timeline, then split into per-song segments before upload.
const {
    stitchedDialogVisible: trackStitchedDialogVisible,
    pendingStitchedFiles: pendingTrackStitchedFiles,
    openStitchedSplit: openTrackStitchedSplit,
    onStitchedCommit: onTrackStitchedCommit,
    onStitchedCancel: onTrackStitchedCancel,
} = useStitchedSplit((file) => addTrackFiles([file]));

const stitchedTrackInput = ref(null);
const pickStitchedTracks = () => stitchedTrackInput.value?.click();
const onStitchedTracksSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (files.length < 2) {
        toast?.add({ severity: 'warn', summary: 'Pick multiple files', detail: 'Select two or more WAV files to stitch and split.', life: 4000 });
        return;
    }
    for (const f of files) {
        if (!/\.wav$/i.test(f.name)) {
            toast?.add({ severity: 'error', summary: 'Invalid file', detail: `${f.name}: only .wav files are allowed`, life: 4000 });
            return;
        }
    }
    openTrackStitchedSplit(files);
};

const confirmDeleteMedia = (item) => confirm.require({
    message: `Delete "${item.name}"? This cannot be undone.`,
    header: 'Delete media',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => router.delete(route('media.destroy', item.id), { preserveScroll: true }),
});

const shareMedia = async (item) => {
    const res = await apiFetch(route('media.share', item.id), { method: 'POST' });
    if (!res.ok) return toast.add({ severity: 'error', summary: 'Share failed', life: 4000 });
    const url = (await res.json()).share_url;
    navigator.clipboard?.writeText(url);
    toast.add({ severity: 'success', summary: 'Link copied', detail: url, life: 3000 });
};

// --- Collecting uploads (anonymous contribution links) -----------------------
const invites = computed(() => props.event.invites ?? []);
const inviteForm = useForm({ label: '', expires_at: null });

const submitInvite = () => {
    inviteForm
        // Expire at the end of the chosen day so "today" isn't already past.
        .transform((data) => ({ ...data, expires_at: data.expires_at ? `${toDateString(data.expires_at)} 23:59:59` : null }))
        .post(route('events.invites.store', props.event.id), {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset(),
        });
};

const copyInvite = (invite) => {
    navigator.clipboard?.writeText(invite.url);
    toast.add({ severity: 'success', summary: 'Upload link copied', detail: invite.url, life: 3000 });
};

const revokeInvite = (invite) => confirm.require({
    message: `Revoke this upload link${invite.label ? ` ("${invite.label}")` : ''}? Anyone holding it can no longer upload. Photos and videos already collected stay.`,
    header: 'Revoke upload link',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => router.delete(route('events.invites.destroy', [props.event.id, invite.id]), { preserveScroll: true }),
});

// --- Image lightbox ----------------------------------------------------------
const lightbox = ref(null);
const openLightbox = (item) => { lightbox.value = item; };
</script>

<template>
    <Head :title="event.name" />
    <Toast />
    <ConfirmDialog v-if="canEdit" />
    <SplitBeforeUploadDialog
        v-if="canEdit"
        v-model:visible="trackSplitDialogVisible"
        :file="pendingTrackSplitFile"
        @commit="onTrackSplitCommit"
        @upload-whole="onTrackSplitUploadWhole"
        @cancel="onTrackSplitCancel"
    />
    <StitchedSplitDialog
        v-if="canEdit"
        v-model:visible="trackStitchedDialogVisible"
        :files="pendingTrackStitchedFiles"
        @commit="onTrackStitchedCommit"
        @cancel="onTrackStitchedCancel"
    />
    <component :is="Layout">
        <template #header>
            <div class="header">
                <div class="title-block">
                    <h2 class="page-title">{{ event.name }}</h2>
                    <div class="meta">
                        <Tag :value="typeLabel(event.type)" severity="secondary" />
                        <span v-if="event.event_date">{{ formatDate(event.event_date) }}</span>
                    </div>
                    <div class="summary">
                        <span><i class="pi pi-volume-up" /> {{ tracks.length }} {{ tracks.length === 1 ? 'track' : 'tracks' }}</span>
                        <span><i class="pi pi-images" /> {{ media.length }} photos &amp; videos</span>
                        <span v-if="event.location"><i class="pi pi-map-marker" /> {{ event.location }}</span>
                    </div>
                </div>
                <div v-if="canEdit" class="actions">
                    <Button v-if="shareUrl" icon="pi pi-copy" label="Copy link" severity="secondary" @click="copyShare" />
                    <Button :icon="shareUrl ? 'pi pi-share-alt' : 'pi pi-share-alt'" :label="shareUrl ? 'Shared' : 'Share'" :severity="shareUrl ? 'success' : 'secondary'" outlined @click="share" />
                    <Button v-if="shareUrl" icon="pi pi-times" severity="secondary" text rounded aria-label="Stop sharing" @click="unshare" />
                    <Button icon="pi pi-pencil" severity="secondary" text rounded aria-label="Edit event" @click="showEdit = true" />
                    <Button icon="pi pi-trash" severity="danger" text rounded aria-label="Delete event" @click="confirmDeleteEvent" />
                </div>
            </div>
        </template>

        <div class="stack">
            <p v-if="event.description" class="description">{{ event.description }}</p>

            <!-- Tracks -->
            <section>
                <div class="section-head">
                    <h3>Tracks</h3>
                    <template v-if="canEdit">
                        <!-- Hidden file pickers; the visible upload buttons live below. -->
                        <input ref="trackInput" type="file" accept=".wav,audio/wav" multiple style="display:none" @change="onTrackSelected" />
                        <input ref="stitchedTrackInput" type="file" accept=".wav,audio/wav" multiple style="display:none" @change="onStitchedTracksSelected" />
                        <Button v-if="assignableTracks.length" icon="pi pi-plus" label="Add existing" size="small" text @click="showAddTracks = true" />
                    </template>
                </div>

                <Card v-if="canEdit && trackUploads.length" class="uploads">
                    <template #content>
                        <div v-for="u in trackUploads" :key="u.name" class="upload-row">
                            <div class="upload-name"><i class="pi pi-cloud-upload" /> <span>{{ u.name }}</span> <Tag :value="u.status" /></div>
                            <ProgressBar :value="u.progress" />
                        </div>
                    </template>
                </Card>

                <div v-if="!tracks.length" class="empty">
                    <i class="pi pi-volume-up" />
                    <p>No tracks in this event yet.</p>
                    <div v-if="canEdit" class="empty-actions">
                        <Button label="Upload a track" icon="pi pi-upload" size="small" @click="pickTrack" />
                        <Button label="Stitch & split" icon="pi pi-objects-column" severity="secondary" outlined size="small" @click="pickStitchedTracks" />
                    </div>
                </div>
                <Card v-else>
                    <template #content>
                        <div v-if="canEdit" class="card-actions">
                            <Button icon="pi pi-upload" label="Upload a track" size="small" outlined @click="pickTrack" />
                            <Button icon="pi pi-objects-column" label="Stitch & split" size="small" outlined severity="secondary" @click="pickStitchedTracks" />
                        </div>
                        <div class="track-list">
                            <div v-for="(track, i) in tracks" :key="track.id" class="track-row">
                                <div class="track-head">
                                    <span class="track-num">{{ i + 1 }}</span>
                                    <Link :href="track.show_url" class="track-link">{{ track.name }}</Link>
                                    <span v-if="formatDuration(track.duration_seconds)" class="track-dur">{{ formatDuration(track.duration_seconds) }}</span>
                                    <Button v-if="canEdit" icon="pi pi-times" severity="secondary" text rounded size="small" aria-label="Remove from event" @click="removeTrack(track)" />
                                </div>
                                <Tag v-if="!track.stream_url" severity="warn" value="Processing" />
                            </div>
                        </div>
                    </template>
                </Card>
            </section>

            <!-- Media -->
            <section>
                <div class="section-head">
                    <h3>Photos &amp; videos <span v-if="media.length" class="count-badge">{{ media.length }}</span></h3>
                    <!-- Hidden file picker; the visible "Upload media" button lives below. -->
                    <input v-if="canUploadMedia" ref="mediaInput" type="file" accept="image/*,video/*" multiple style="display:none" @change="onMediaSelected" />
                </div>

                <Card v-if="canUploadMedia && uploads.length" class="uploads">
                    <template #content>
                        <div v-for="u in uploads" :key="u.name" class="upload-row">
                            <div class="upload-name"><i class="pi pi-cloud-upload" /> <span>{{ u.name }}</span> <Tag :value="u.status" /></div>
                            <ProgressBar :value="u.progress" />
                        </div>
                    </template>
                </Card>

                <div v-if="!media.length" class="empty">
                    <i class="pi pi-images" />
                    <p>No photos or videos yet.</p>
                    <Button v-if="canUploadMedia" label="Upload media" icon="pi pi-upload" size="small" @click="pickMedia" />
                </div>

                <div v-else>
                    <div v-if="canUploadMedia" class="card-actions">
                        <Button icon="pi pi-upload" label="Upload media" size="small" outlined @click="pickMedia" />
                    </div>
                    <div class="media-grid">
                        <div v-for="item in media" :key="item.id" class="media-tile">
                        <button class="thumb-btn" @click="openLightbox(item)" :aria-label="`Open ${item.name}`">
                            <img v-if="item.thumb_url || (item.kind === 'image' && item.url)" :src="item.thumb_url || item.url" :alt="item.name" loading="lazy" />
                            <div v-else class="placeholder"><i :class="item.kind === 'video' ? 'pi pi-video' : 'pi pi-image'" /></div>
                            <span v-if="item.kind === 'video'" class="play-badge"><i class="pi pi-play-circle" /></span>
                        </button>
                        <div class="tile-actions">
                            <a v-if="item.download_url" :href="item.download_url" :download="item.name" class="tile-download" aria-label="Download">
                                <Button icon="pi pi-download" severity="secondary" text rounded size="small" tabindex="-1" />
                            </a>
                            <template v-if="canEdit">
                                <Button icon="pi pi-share-alt" severity="secondary" text rounded size="small" aria-label="Share" @click="shareMedia(item)" />
                                <Button icon="pi pi-trash" severity="danger" text rounded size="small" aria-label="Delete" @click="confirmDeleteMedia(item)" />
                            </template>
                        </div>
                        <div v-if="item.contributor_name" class="tile-by" :title="item.contributor_name">{{ item.contributor_name }}</div>
                    </div>
                    </div>
                </div>
            </section>

            <!-- Collect uploads — anonymous contribution links (owner only) -->
            <section v-if="canEdit">
                <div class="section-head">
                    <h3>Collect uploads</h3>
                </div>
                <Card>
                    <template #content>
                        <p class="collect-intro">
                            Create a link you can text to band members or the audience. Anyone
                            with it can add photos and videos straight from their phone without
                            logging in. The uploads land in this event.
                        </p>

                        <form class="invite-form" @submit.prevent="submitInvite">
                            <div class="field">
                                <label for="i-label">Label <span class="optional">(optional)</span></label>
                                <InputText id="i-label" v-model="inviteForm.label" placeholder="e.g. Band, Audience" :invalid="!!inviteForm.errors.label" />
                            </div>
                            <div class="field">
                                <label for="i-exp">Expires <span class="optional">(optional)</span></label>
                                <DatePicker id="i-exp" v-model="inviteForm.expires_at" dateFormat="yy-mm-dd" :minDate="new Date()" showIcon iconDisplay="input" placeholder="Never" />
                            </div>
                            <Button type="submit" icon="pi pi-link" label="Create link" :loading="inviteForm.processing" />
                        </form>

                        <ul v-if="invites.length" class="invite-list">
                            <li v-for="invite in invites" :key="invite.id" class="invite-row">
                                <div class="invite-meta">
                                    <span class="invite-label">{{ invite.label || 'Upload link' }}</span>
                                    <span class="invite-sub">
                                        {{ invite.uploads_count }} upload{{ invite.uploads_count === 1 ? '' : 's' }}
                                        <template v-if="invite.expires_at"> · expires {{ formatDate(invite.expires_at.slice(0, 10)) }}</template>
                                        <Tag v-if="!invite.active" value="Expired" severity="warn" />
                                    </span>
                                    <code class="invite-url">{{ invite.url }}</code>
                                </div>
                                <div class="invite-actions">
                                    <Button icon="pi pi-copy" label="Copy" size="small" severity="secondary" @click="copyInvite(invite)" />
                                    <Button icon="pi pi-times" severity="danger" text rounded size="small" aria-label="Revoke link" @click="revokeInvite(invite)" />
                                </div>
                            </li>
                        </ul>
                    </template>
                </Card>
            </section>
        </div>

        <!-- Edit event -->
        <Dialog v-if="canEdit" v-model:visible="showEdit" modal header="Edit event" :style="{ width: '32rem' }">
            <div class="form">
                <div class="field">
                    <label for="e-name">Name</label>
                    <InputText id="e-name" v-model="editForm.name" :invalid="!!editForm.errors.name" />
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="e-type">Type</label>
                        <Select id="e-type" v-model="editForm.type" :options="options" optionLabel="label" optionValue="value" />
                    </div>
                    <div class="field">
                        <label for="e-date">Date</label>
                        <DatePicker id="e-date" v-model="editForm.event_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
                    </div>
                </div>
                <div class="field">
                    <label for="e-loc">Location</label>
                    <InputText id="e-loc" v-model="editForm.location" />
                </div>
                <div class="field">
                    <label for="e-desc">Notes</label>
                    <Textarea id="e-desc" v-model="editForm.description" rows="3" autoResize />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showEdit = false" />
                <Button label="Save" icon="pi pi-check" :loading="editForm.processing" :disabled="!editForm.name.trim()" @click="submitEdit" />
            </template>
        </Dialog>

        <!-- Add tracks -->
        <Dialog v-if="canEdit" v-model:visible="showAddTracks" modal header="Add tracks to event" :style="{ width: '30rem' }">
            <MultiSelect v-model="selectedTrackIds" :options="assignableTracks" optionLabel="name" optionValue="id" filter display="chip" placeholder="Select tracks" class="full" />
            <template #footer>
                <Button label="Cancel" text @click="showAddTracks = false" />
                <Button label="Add" icon="pi pi-check" :disabled="!selectedTrackIds.length" @click="submitAddTracks" />
            </template>
        </Dialog>

        <!-- Image lightbox -->
        <Dialog :visible="!!lightbox" modal dismissableMask :showHeader="false" :style="{ width: 'auto', maxWidth: '92vw' }" @update:visible="lightbox = null">
            <template v-if="lightbox">
                <video v-if="lightbox.kind === 'video'" :src="lightbox.url" controls autoplay class="lightbox-img" />
                <img v-else :src="lightbox.url" :alt="lightbox.name" class="lightbox-img" />
            </template>
        </Dialog>
    </component>
</template>

<style scoped>
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.page-title { font-size: 1.4rem; font-weight: 600; margin: 0 0 0.4rem; }
.meta { display: flex; align-items: center; gap: 0.9rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.meta i { margin-right: 0.25rem; }
.summary { display: flex; align-items: center; flex-wrap: wrap; gap: 1.1rem; margin-top: 0.5rem; font-size: 0.85rem; color: var(--p-text-muted-color); }
.summary i { margin-right: 0.3rem; }
.actions { display: flex; align-items: center; gap: 0.4rem; }
.stack { display: flex; flex-direction: column; gap: 2rem; }
.description { margin: 0; white-space: pre-wrap; color: var(--p-text-color); }
.section-head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.section-head h3 { font-size: 1.05rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.count-badge { font-size: 0.75rem; font-weight: 600; color: var(--p-text-muted-color); background: var(--p-surface-200); border-radius: 999px; padding: 0.05rem 0.5rem; }
.track-list { display: flex; flex-direction: column; gap: 1rem; }
.track-row { display: flex; flex-direction: column; gap: 0.4rem; }
.track-head { display: flex; align-items: center; gap: 0.6rem; }
.track-num { flex: 0 0 auto; width: 1.4rem; text-align: right; font-variant-numeric: tabular-nums; color: var(--p-text-muted-color); font-size: 0.85rem; }
.track-link { flex: 1 1 auto; min-width: 0; color: var(--p-primary-color); text-decoration: none; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.track-link:hover { text-decoration: underline; }
.track-dur { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-size: 0.85rem; color: var(--p-text-muted-color); }
.empty { display: flex; flex-direction: column; align-items: center; gap: 0.6rem; padding: 2.5rem 1rem; border: 1px dashed var(--p-content-border-color); border-radius: 10px; color: var(--p-text-muted-color); }
.empty i { font-size: 1.75rem; }
.empty p { margin: 0; }
.uploads { margin-bottom: 1rem; }
.card-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-bottom: 0.75rem; }
.empty-actions { display: flex; gap: 0.5rem; }
.upload-row { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.75rem; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.75rem; }
.media-tile { position: relative; }
.thumb-btn { display: block; width: 100%; aspect-ratio: 1; padding: 0; border: 0; border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--p-surface-100); }
.thumb-btn img { width: 100%; height: 100%; object-fit: cover; display: block; }
.placeholder { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--p-text-muted-color); font-size: 2rem; }
.play-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 2.5rem; text-shadow: 0 2px 8px rgba(0,0,0,0.5); pointer-events: none; }
.tile-actions { position: absolute; top: 0.25rem; right: 0.25rem; display: flex; gap: 0.15rem; background: rgba(0,0,0,0.45); border-radius: 6px; opacity: 0; transition: opacity 0.12s; }
.tile-actions :deep(.p-button) { color: #fff; }
.tile-download { display: inline-flex; line-height: 0; }
.media-tile:hover .tile-actions, .media-tile:focus-within .tile-actions { opacity: 1; }
.lightbox-img { max-width: 90vw; max-height: 85vh; display: block; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-select), .field :deep(.p-datepicker) { width: 100%; }
.field-row { display: flex; gap: 1rem; }
.full { width: 100%; }
.optional { font-weight: 400; color: var(--p-text-muted-color); }
.tile-by { position: absolute; left: 0.35rem; bottom: 0.35rem; right: 0.35rem; font-size: 0.7rem; color: #fff; background: rgba(0,0,0,0.45); border-radius: 5px; padding: 0.1rem 0.35rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.collect-intro { margin: 0 0 1rem; color: var(--p-text-muted-color); font-size: 0.9rem; max-width: 42rem; }
.invite-form { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.invite-form .field { flex: 0 1 14rem; }
.invite-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.75rem; }
.invite-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem; border: 1px solid var(--p-surface-200); border-radius: 8px; }
.invite-meta { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
.invite-label { font-weight: 600; }
.invite-sub { font-size: 0.8rem; color: var(--p-text-muted-color); display: flex; align-items: center; gap: 0.4rem; }
.invite-url { font-size: 0.78rem; color: var(--p-text-muted-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 32rem; }
.invite-actions { display: flex; align-items: center; gap: 0.25rem; flex-shrink: 0; }
</style>
