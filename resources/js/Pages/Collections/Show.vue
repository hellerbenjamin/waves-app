<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import MultiSelect from 'primevue/multiselect';
import ProgressBar from 'primevue/progressbar';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import { useS3Upload, apiFetch } from '@/composables/useS3Upload';

const props = defineProps({
    collection: { type: Object, required: true },
    assignableMedia: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: true },
});

const mediaDownloadAllUrl = computed(() => props.collection.media_download_all_url ?? null);

// Owners get the app chrome; public share links render under a guest layout.
const Layout = props.canEdit ? AuthenticatedLayout : PublicLayout;
const toast = useToast();
const confirm = props.canEdit ? useConfirm() : null;

const media = computed(() => props.collection.media ?? []);

// --- Editing the collection details ------------------------------------------
const showEdit = ref(false);
const editForm = useForm({
    name: props.collection.name,
    description: props.collection.description ?? '',
});

const submitEdit = () => {
    editForm.patch(route('collections.update', props.collection.id), {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
};

const confirmDeleteCollection = () => confirm.require({
    message: `Delete "${props.collection.name}"? Its photos and videos stay in your library; only the collection is removed.`,
    header: 'Delete collection',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: () => router.delete(route('collections.destroy', props.collection.id)),
});

// --- Sharing -----------------------------------------------------------------
const shareUrl = ref(props.collection.share_url);

const share = async () => {
    if (shareUrl.value) return copyShare();
    const res = await apiFetch(route('collections.share', props.collection.id), { method: 'POST' });
    if (!res.ok) return toast.add({ severity: 'error', summary: 'Share failed', life: 4000 });
    shareUrl.value = (await res.json()).share_url;
    copyShare();
};

const copyShare = () => {
    navigator.clipboard?.writeText(shareUrl.value);
    toast.add({ severity: 'success', summary: 'Link copied', detail: shareUrl.value, life: 3000 });
};

const unshare = async () => {
    const res = await apiFetch(route('collections.unshare', props.collection.id), { method: 'DELETE' });
    if (res.ok) { shareUrl.value = null; toast.add({ severity: 'info', summary: 'Sharing disabled', life: 2500 }); }
};

// --- Adding existing media ---------------------------------------------------
const showAddMedia = ref(false);
const selectedMediaIds = ref([]);

const submitAddMedia = () => {
    if (!selectedMediaIds.value.length) return;
    router.post(route('collections.media.attach', props.collection.id), { media_ids: selectedMediaIds.value }, {
        preserveScroll: true,
        onSuccess: () => { showAddMedia.value = false; selectedMediaIds.value = []; },
    });
};

const removeMedia = (item) => router.delete(route('collections.media.detach', [props.collection.id, item.id]), { preserveScroll: true });

// --- Media uploads (straight into this collection) ---------------------------
const ALLOWED = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
];
const mediaInput = ref(null);

// Only owners upload into a collection; the share view is read/download only.
const canUploadMedia = computed(() => props.canEdit);

const { uploads, addFiles } = useS3Upload({
    routes: {
        uploadUrl: route('media.upload-url'),
        multipartCreate: route('media.multipart.create'),
        multipartSign: route('media.multipart.sign'),
        multipartComplete: route('media.multipart.complete'),
        multipartAbort: route('media.multipart.abort'),
        cleanup: route('media.cleanup'),
        store: route('media.store'),
    },
    initBody: (file) => ({ filename: file.name, size: file.size, content_type: file.type }),
    validate: (file) => ALLOWED.includes(file.type) ? null : 'unsupported file type',
    finalize: (file, key) => new Promise((resolve, reject) => {
        router.post(route('media.store'), {
            s3_key: key,
            original_name: file.name,
            mime: file.type,
            size: file.size,
            collection_id: props.collection.id,
        }, {
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

const confirmDeleteMedia = (item) => confirm.require({
    message: `Delete "${item.name}"? This removes the file from your library and cannot be undone.`,
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

// --- Image / video lightbox --------------------------------------------------
const lightbox = ref(null);
const previewRotation = ref(0);
const openLightbox = (item) => { lightbox.value = item; previewRotation.value = 0; };

const lightboxVideoStyle = computed(() => {
    const deg = ((previewRotation.value % 360) + 360) % 360;
    const swapped = deg === 90 || deg === 270;
    return {
        transform: `rotate(${deg}deg)`,
        maxWidth: swapped ? '85vh' : '90vw',
        maxHeight: swapped ? '90vw' : '85vh',
    };
});

const rotateVideo = (direction) => {
    if (!lightbox.value) return;
    previewRotation.value += direction === 'cw' ? 90 : -90;
    router.post(route('media.rotate', lightbox.value.id), { direction }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.add({
            severity: 'info',
            summary: 'Rotating video',
            detail: 'The saved video will finish updating a moment after processing.',
            life: 4000,
        }),
    });
};
</script>

<template>
    <Head :title="collection.name" />
    <Toast />
    <ConfirmDialog v-if="canEdit" />
    <component :is="Layout">
        <template #header>
            <div class="header">
                <div class="title-block">
                    <h2 class="page-title">{{ collection.name }}</h2>
                    <div class="summary">
                        <span><i class="pi pi-images" /> {{ media.length }} photos &amp; videos</span>
                    </div>
                </div>
                <div v-if="canEdit" class="actions">
                    <Button v-if="shareUrl" icon="pi pi-copy" label="Copy link" severity="secondary" @click="copyShare" />
                    <Button icon="pi pi-share-alt" :label="shareUrl ? 'Shared' : 'Share'" :severity="shareUrl ? 'success' : 'secondary'" outlined @click="share" />
                    <Button v-if="shareUrl" icon="pi pi-times" severity="secondary" text rounded aria-label="Stop sharing" @click="unshare" />
                    <Button icon="pi pi-pencil" severity="secondary" text rounded aria-label="Edit collection" @click="showEdit = true" />
                    <Button icon="pi pi-trash" severity="danger" text rounded aria-label="Delete collection" @click="confirmDeleteCollection" />
                </div>
            </div>
        </template>

        <div class="stack">
            <p v-if="collection.description" class="description">{{ collection.description }}</p>

            <section>
                <div class="section-head">
                    <h3>Photos &amp; videos <span v-if="media.length" class="count-badge">{{ media.length }}</span></h3>
                    <input v-if="canUploadMedia" ref="mediaInput" type="file" accept="image/*,video/*" multiple style="display:none" @change="onMediaSelected" />
                    <Button v-if="canEdit && assignableMedia.length" icon="pi pi-plus" label="Add existing" size="small" text @click="showAddMedia = true" />
                    <a v-if="mediaDownloadAllUrl && media.length" :href="mediaDownloadAllUrl" download>
                        <Button icon="pi pi-download" label="Download all" size="small" severity="secondary" outlined tabindex="-1" />
                    </a>
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
                    <div v-if="canEdit" class="empty-actions">
                        <Button label="Upload media" icon="pi pi-upload" size="small" @click="pickMedia" />
                        <Button v-if="assignableMedia.length" label="Add existing" icon="pi pi-plus" size="small" severity="secondary" outlined @click="showAddMedia = true" />
                    </div>
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
                                    <Button icon="pi pi-minus-circle" severity="secondary" text rounded size="small" aria-label="Remove from collection" @click="removeMedia(item)" />
                                    <Button icon="pi pi-trash" severity="danger" text rounded size="small" aria-label="Delete" @click="confirmDeleteMedia(item)" />
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Edit collection -->
        <Dialog v-if="canEdit" v-model:visible="showEdit" modal header="Edit collection" :style="{ width: '32rem' }">
            <div class="form">
                <div class="field">
                    <label for="ce-name">Name</label>
                    <InputText id="ce-name" v-model="editForm.name" :invalid="!!editForm.errors.name" />
                </div>
                <div class="field">
                    <label for="ce-desc">Notes</label>
                    <Textarea id="ce-desc" v-model="editForm.description" rows="3" autoResize />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showEdit = false" />
                <Button label="Save" icon="pi pi-check" :loading="editForm.processing" :disabled="!editForm.name.trim()" @click="submitEdit" />
            </template>
        </Dialog>

        <!-- Add existing media -->
        <Dialog v-if="canEdit" v-model:visible="showAddMedia" modal header="Add media to collection" :style="{ width: '30rem' }">
            <MultiSelect v-model="selectedMediaIds" :options="assignableMedia" optionLabel="name" optionValue="id" filter display="chip" placeholder="Select photos and videos" class="full" />
            <template #footer>
                <Button label="Cancel" text @click="showAddMedia = false" />
                <Button label="Add" icon="pi pi-check" :disabled="!selectedMediaIds.length" @click="submitAddMedia" />
            </template>
        </Dialog>

        <!-- Lightbox -->
        <Dialog :visible="!!lightbox" modal dismissableMask :showHeader="false" :style="{ width: 'auto', maxWidth: '92vw' }" :class="lightbox?.kind === 'video' ? 'lightbox-video-dialog' : ''" @update:visible="lightbox = null">
            <template v-if="lightbox">
                <div v-if="canEdit && lightbox.kind === 'video'" class="rotate-toolbar">
                    <Button icon="pi pi-replay" text rounded severity="secondary" aria-label="Rotate left" @click="rotateVideo('ccw')" />
                    <Button icon="pi pi-refresh" text rounded severity="secondary" aria-label="Rotate right" @click="rotateVideo('cw')" />
                </div>
                <video v-if="lightbox.kind === 'video'" :src="lightbox.url" :style="lightboxVideoStyle" controls autoplay class="lightbox-img lightbox-video" />
                <img v-else :src="lightbox.url" :alt="lightbox.name" class="lightbox-img" />
            </template>
        </Dialog>
    </component>
</template>

<style scoped>
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.page-title { font-size: 1.4rem; font-weight: 600; margin: 0 0 0.4rem; }
.summary { display: flex; align-items: center; flex-wrap: wrap; gap: 1.1rem; margin-top: 0.5rem; font-size: 0.85rem; color: var(--p-text-muted-color); }
.summary i { margin-right: 0.3rem; }
.actions { display: flex; align-items: center; gap: 0.4rem; }
.stack { display: flex; flex-direction: column; gap: 2rem; }
.description { margin: 0; white-space: pre-wrap; color: var(--p-text-color); }
.section-head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.section-head h3 { font-size: 1.05rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.count-badge { font-size: 0.75rem; font-weight: 600; color: var(--p-text-muted-color); background: var(--p-surface-200); border-radius: 999px; padding: 0.05rem 0.5rem; }
.empty { display: flex; flex-direction: column; align-items: center; gap: 0.6rem; padding: 2.5rem 1rem; border: 1px dashed var(--p-content-border-color); border-radius: 10px; color: var(--p-text-muted-color); }
.empty i { font-size: 1.75rem; }
.empty p { margin: 0; }
.empty-actions { display: flex; gap: 0.5rem; }
.uploads { margin-bottom: 1rem; }
.card-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-bottom: 0.75rem; }
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
.lightbox-video { transition: transform 0.2s ease; }
.rotate-toolbar { position: absolute; top: 0.75rem; left: 0.75rem; z-index: 10; display: flex; gap: 0.25rem; background: rgba(0, 0, 0, 0.45); border-radius: 999px; padding: 0.15rem; }
.rotate-toolbar :deep(.p-button) { color: #fff; }
:global(.lightbox-video-dialog .p-dialog-content) { position: relative; }
@media (max-width: 640px) {
    :global(.lightbox-video-dialog) { width: 100vw !important; max-width: 100vw !important; height: 100dvh !important; margin: 0 !important; border-radius: 0 !important; }
    :global(.lightbox-video-dialog .p-dialog-content) { padding: 0 !important; height: 100%; background: #000; display: flex; align-items: center; }
    .lightbox-video { max-width: 100vw; max-height: 100dvh; width: 100%; height: 100%; object-fit: contain; }
}
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-textarea) { width: 100%; }
.full { width: 100%; }
</style>
