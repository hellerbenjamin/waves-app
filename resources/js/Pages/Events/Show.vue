<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
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

const props = defineProps({
    event: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    assignableTracks: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: true },
});

// Owners get the app chrome; public share links render under a guest layout.
const Layout = props.canEdit ? AuthenticatedLayout : PublicLayout;
const toast = props.canEdit ? useToast() : null;
const confirm = props.canEdit ? useConfirm() : null;

const options = computed(() => typeOptions(props.types));
const tracks = computed(() => props.event.tracks ?? []);
const media = computed(() => props.event.media ?? []);
const images = computed(() => media.value.filter(m => m.kind === 'image'));
const videos = computed(() => media.value.filter(m => m.kind === 'video'));

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

const { uploads, addFiles } = useS3Upload({
    routes: {
        uploadUrl: route('media.upload-url'),
        multipartCreate: route('media.multipart.create'),
        multipartSign: route('media.multipart.sign'),
        multipartComplete: route('media.multipart.complete'),
        multipartAbort: route('media.multipart.abort'),
        cleanup: route('media.cleanup'),
    },
    initBody: (file) => ({ filename: file.name, size: file.size, content_type: file.type }),
    validate: (file) => ALLOWED.includes(file.type) ? null : 'unsupported file type',
    finalize: (file, key) => new Promise((resolve, reject) => {
        router.post(route('media.store'), {
            s3_key: key,
            original_name: file.name,
            mime: file.type,
            size: file.size,
            event_id: props.event.id,
        }, { preserveScroll: true, onSuccess: resolve, onError: reject });
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
    <Toast v-if="canEdit" />
    <ConfirmDialog v-if="canEdit" />

    <component :is="Layout">
        <template #header>
            <div class="header">
                <div class="title-block">
                    <h2 class="page-title">{{ event.name }}</h2>
                    <div class="meta">
                        <Tag :value="typeLabel(event.type)" severity="secondary" />
                        <span v-if="event.event_date">{{ formatDate(event.event_date) }}</span>
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
                    <Button v-if="canEdit && assignableTracks.length" icon="pi pi-plus" label="Add tracks" size="small" text @click="showAddTracks = true" />
                </div>
                <Message v-if="!tracks.length" severity="info" :closable="false">No tracks in this event yet.</Message>
                <Card v-else>
                    <template #content>
                        <div class="track-list">
                            <div v-for="track in tracks" :key="track.id" class="track-row">
                                <div class="track-head">
                                    <Link v-if="canEdit" :href="route('tracks.show', track.id)" class="track-link">{{ track.name }}</Link>
                                    <span v-else class="track-link">{{ track.name }}</span>
                                    <Button v-if="canEdit" icon="pi pi-times" severity="secondary" text rounded size="small" aria-label="Remove from event" @click="removeTrack(track)" />
                                </div>
                                <audio v-if="track.peaks_ready || track.stream_url" :src="track.stream_url" :crossorigin="track.stream_cross_origin" controls preload="none" class="audio" />
                                <Tag v-else severity="warn" value="Processing" />
                            </div>
                        </div>
                    </template>
                </Card>
            </section>

            <!-- Media -->
            <section>
                <div class="section-head">
                    <h3>Photos &amp; videos</h3>
                    <template v-if="canEdit">
                        <Button icon="pi pi-upload" label="Upload media" size="small" @click="pickMedia" />
                        <input ref="mediaInput" type="file" accept="image/*,video/*" multiple style="display:none" @change="onMediaSelected" />
                    </template>
                </div>

                <Card v-if="canEdit && uploads.length" class="uploads">
                    <template #content>
                        <div v-for="u in uploads" :key="u.name" class="upload-row">
                            <div class="upload-name"><i class="pi pi-cloud-upload" /> <span>{{ u.name }}</span> <Tag :value="u.status" /></div>
                            <ProgressBar :value="u.progress" />
                        </div>
                    </template>
                </Card>

                <Message v-if="!media.length" severity="info" :closable="false">No photos or videos yet.</Message>

                <div v-if="images.length" class="media-grid">
                    <div v-for="item in images" :key="item.id" class="media-tile">
                        <button class="thumb-btn" @click="openLightbox(item)">
                            <img v-if="item.thumb_url || item.url" :src="item.thumb_url || item.url" :alt="item.name" loading="lazy" />
                            <div v-else class="placeholder"><i class="pi pi-spin pi-spinner" /></div>
                        </button>
                        <div class="tile-actions" v-if="canEdit">
                            <Button icon="pi pi-share-alt" severity="secondary" text rounded size="small" aria-label="Share" @click="shareMedia(item)" />
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small" aria-label="Delete" @click="confirmDeleteMedia(item)" />
                        </div>
                        <div v-if="item.contributor_name" class="tile-by" :title="item.contributor_name">{{ item.contributor_name }}</div>
                    </div>
                </div>

                <div v-if="videos.length" class="video-list">
                    <div v-for="item in videos" :key="item.id" class="video-card">
                        <video :src="item.url" :poster="item.thumb_url || undefined" controls preload="metadata" />
                        <div class="video-foot">
                            <span class="video-name" :title="item.name">
                                {{ item.name }}
                                <em v-if="item.contributor_name" class="by"> · by {{ item.contributor_name }}</em>
                            </span>
                            <div class="tile-actions" v-if="canEdit">
                                <Button icon="pi pi-share-alt" severity="secondary" text rounded size="small" aria-label="Share" @click="shareMedia(item)" />
                                <Button icon="pi pi-trash" severity="danger" text rounded size="small" aria-label="Delete" @click="confirmDeleteMedia(item)" />
                            </div>
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
                            with it can add photos and videos straight from their phone — no
                            account needed. The uploads land in this event.
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
            <img v-if="lightbox" :src="lightbox.url" :alt="lightbox.name" class="lightbox-img" />
        </Dialog>
    </component>
</template>

<style scoped>
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.page-title { font-size: 1.4rem; font-weight: 600; margin: 0 0 0.4rem; }
.meta { display: flex; align-items: center; gap: 0.9rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.meta i { margin-right: 0.25rem; }
.actions { display: flex; align-items: center; gap: 0.4rem; }
.stack { display: flex; flex-direction: column; gap: 2rem; }
.description { margin: 0; white-space: pre-wrap; color: var(--p-text-color); }
.section-head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.section-head h3 { font-size: 1.05rem; font-weight: 600; margin: 0; }
.track-list { display: flex; flex-direction: column; gap: 1rem; }
.track-row { display: flex; flex-direction: column; gap: 0.4rem; }
.track-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.track-link { color: var(--p-primary-color); text-decoration: none; font-weight: 500; }
.track-link:hover { text-decoration: underline; }
.audio { width: 100%; height: 2.5rem; }
.uploads { margin-bottom: 1rem; }
.upload-row { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.75rem; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.75rem; }
.media-tile { position: relative; }
.thumb-btn { display: block; width: 100%; aspect-ratio: 1; padding: 0; border: 0; border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--p-surface-100); }
.thumb-btn img { width: 100%; height: 100%; object-fit: cover; display: block; }
.placeholder { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--p-text-muted-color); }
.tile-actions { position: absolute; top: 0.25rem; right: 0.25rem; display: flex; gap: 0.15rem; background: rgba(0,0,0,0.35); border-radius: 6px; }
.tile-actions :deep(.p-button) { color: #fff; }
.video-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); gap: 1rem; margin-top: 1rem; }
.video-card { display: flex; flex-direction: column; gap: 0.4rem; }
.video-card video { width: 100%; border-radius: 8px; background: #000; }
.video-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.video-name { font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.video-foot .tile-actions { position: static; background: transparent; }
.video-foot .tile-actions :deep(.p-button) { color: inherit; }
.lightbox-img { max-width: 90vw; max-height: 85vh; display: block; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-select), .field :deep(.p-datepicker) { width: 100%; }
.field-row { display: flex; gap: 1rem; }
.full { width: 100%; }
.optional { font-weight: 400; color: var(--p-text-muted-color); }
.by { font-style: normal; color: var(--p-text-muted-color); }
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
