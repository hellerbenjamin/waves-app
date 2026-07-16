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
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import { apiFetch } from '@/composables/useS3Upload';

const props = defineProps({
    collection: { type: Object, required: true },
    canEdit: { type: Boolean, default: true },
});

// Owners get the app chrome; public share links render under a guest layout.
const Layout = props.canEdit ? AuthenticatedLayout : PublicLayout;
const toast = useToast();
const confirm = props.canEdit ? useConfirm() : null;

const tracks = computed(() => props.collection.tracks ?? []);
const media = computed(() => props.collection.media ?? []);
const mediaDownloadAllUrl = computed(() => props.collection.media_download_all_url ?? null);

// mm:ss for a track's playable length; null when peaks haven't been extracted yet.
const formatDuration = (s) => {
    if (s == null) return null;
    const m = Math.floor(s / 60);
    const sec = Math.round(s % 60);
    return `${m}:${String(sec).padStart(2, '0')}`;
};

const refresh = () => router.reload({ only: ['collection'], preserveScroll: true });

// --- Editing collection details ---------------------------------------------
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

const confirmDelete = () => confirm.require({
    message: `Delete "${props.collection.name}"? The tracks, photos, and videos it points to stay in your library; only the collection is removed.`,
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

// --- Removing items (detach; the underlying item survives) --------------------
const removeItem = (type, id) => router.delete(route('collections.items.detach', props.collection.id), {
    data: { type, ids: [id] },
    preserveScroll: true,
});

// --- Add items picker --------------------------------------------------------
const showAdd = ref(false);
const query = ref('');
const candidates = ref({ tracks: [], media: [] });
const loadingCandidates = ref(false);
const selectedTracks = ref(new Set());
const selectedMedia = ref(new Set());

// Items already in the collection can't be added again.
const memberTrackIds = computed(() => new Set(tracks.value.map((t) => t.id)));
const memberMediaIds = computed(() => new Set(media.value.map((m) => m.id)));

const loadCandidates = async () => {
    loadingCandidates.value = true;
    try {
        const res = await apiFetch(route('collections.candidates', { q: query.value || undefined }));
        candidates.value = res.ok ? await res.json() : { tracks: [], media: [] };
    } finally {
        loadingCandidates.value = false;
    }
};

const openAdd = () => {
    selectedTracks.value = new Set();
    selectedMedia.value = new Set();
    query.value = '';
    showAdd.value = true;
    loadCandidates();
};

const toggle = (set, id) => {
    const next = new Set(set.value);
    next.has(id) ? next.delete(id) : next.add(id);
    set.value = next;
};

const selectedCount = computed(() => selectedTracks.value.size + selectedMedia.value.size);

const submitAdd = () => {
    const trackIds = [...selectedTracks.value];
    const mediaIds = [...selectedMedia.value];
    const done = () => {
        showAdd.value = false;
        toast.add({ severity: 'success', summary: 'Added to collection', life: 2500 });
    };
    const postMedia = () => {
        if (!mediaIds.length) return done();
        router.post(route('collections.items.attach', props.collection.id), { type: 'media', ids: mediaIds }, {
            preserveScroll: true, onSuccess: done,
        });
    };
    if (trackIds.length) {
        router.post(route('collections.items.attach', props.collection.id), { type: 'track', ids: trackIds }, {
            preserveScroll: true, onSuccess: postMedia,
        });
    } else {
        postMedia();
    }
};

// --- Media lightbox ----------------------------------------------------------
const lightbox = ref(null);
const openLightbox = (item) => { lightbox.value = item; };
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
                        <span><i class="pi pi-volume-up" /> {{ tracks.length }} {{ tracks.length === 1 ? 'track' : 'tracks' }}</span>
                        <span><i class="pi pi-images" /> {{ media.length }} photos &amp; videos</span>
                    </div>
                </div>
                <div v-if="canEdit" class="actions">
                    <Button icon="pi pi-plus" label="Add items" size="small" @click="openAdd" />
                    <Button v-if="shareUrl" icon="pi pi-copy" label="Copy link" severity="secondary" @click="copyShare" />
                    <Button icon="pi pi-share-alt" :label="shareUrl ? 'Shared' : 'Share'" :severity="shareUrl ? 'success' : 'secondary'" outlined @click="share" />
                    <Button v-if="shareUrl" icon="pi pi-times" severity="secondary" text rounded aria-label="Stop sharing" @click="unshare" />
                    <Button icon="pi pi-pencil" severity="secondary" text rounded aria-label="Edit collection" @click="showEdit = true" />
                    <Button icon="pi pi-trash" severity="danger" text rounded aria-label="Delete collection" @click="confirmDelete" />
                </div>
            </div>
        </template>

        <div class="stack">
            <p v-if="collection.description" class="description">{{ collection.description }}</p>

            <div v-if="!tracks.length && !media.length" class="empty">
                <i class="pi pi-folder-open" />
                <p>This collection is empty.</p>
                <Button v-if="canEdit" label="Add items" icon="pi pi-plus" size="small" @click="openAdd" />
            </div>

            <!-- Tracks -->
            <section v-if="tracks.length">
                <div class="section-head">
                    <h3>Tracks <span class="count-badge">{{ tracks.length }}</span></h3>
                </div>
                <Card>
                    <template #content>
                        <div class="track-list">
                            <div v-for="(track, i) in tracks" :key="track.id" class="track-row">
                                <div class="track-head">
                                    <span class="track-num">{{ i + 1 }}</span>
                                    <Link :href="track.show_url" class="track-link">{{ track.name }}</Link>
                                    <span v-if="formatDuration(track.duration_seconds)" class="track-dur">{{ formatDuration(track.duration_seconds) }}</span>
                                    <Button v-if="canEdit" icon="pi pi-times" severity="secondary" text rounded size="small" aria-label="Remove from collection" @click="removeItem('track', track.id)" />
                                </div>
                                <Tag v-if="!track.ready" severity="warn" value="Processing" />
                            </div>
                        </div>
                    </template>
                </Card>
            </section>

            <!-- Media -->
            <section v-if="media.length">
                <div class="section-head">
                    <h3>Photos &amp; videos <span class="count-badge">{{ media.length }}</span></h3>
                    <a v-if="mediaDownloadAllUrl" :href="mediaDownloadAllUrl" download>
                        <Button icon="pi pi-download" label="Download all" size="small" severity="secondary" outlined tabindex="-1" />
                    </a>
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
                            <Button v-if="canEdit" icon="pi pi-times" severity="danger" text rounded size="small" aria-label="Remove from collection" @click="removeItem('media', item.id)" />
                        </div>
                        <div v-if="item.contributor_name" class="tile-by" :title="item.contributor_name">{{ item.contributor_name }}</div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Edit collection -->
        <Dialog v-if="canEdit" v-model:visible="showEdit" modal header="Edit collection" :style="{ width: '32rem' }">
            <div class="form">
                <div class="field">
                    <label for="c-name">Name</label>
                    <InputText id="c-name" v-model="editForm.name" :invalid="!!editForm.errors.name" />
                </div>
                <div class="field">
                    <label for="c-desc">Description</label>
                    <Textarea id="c-desc" v-model="editForm.description" rows="3" autoResize />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showEdit = false" />
                <Button label="Save" icon="pi pi-check" :loading="editForm.processing" :disabled="!editForm.name.trim()" @click="submitEdit" />
            </template>
        </Dialog>

        <!-- Add items -->
        <Dialog v-if="canEdit" v-model:visible="showAdd" modal header="Add items" :style="{ width: '48rem' }" :contentStyle="{ maxHeight: '70vh' }">
            <div class="picker">
                <span class="p-input-icon-left search">
                    <InputText v-model="query" placeholder="Search your tracks and media…" class="full" @input="loadCandidates" />
                </span>

                <div v-if="loadingCandidates" class="picker-loading"><i class="pi pi-spin pi-spinner" /> Loading…</div>

                <template v-else>
                    <h4 v-if="candidates.tracks.length" class="picker-head">Tracks</h4>
                    <ul v-if="candidates.tracks.length" class="pick-list">
                        <li v-for="t in candidates.tracks" :key="t.id" class="pick-row" :class="{ disabled: memberTrackIds.has(t.id) }">
                            <label class="pick-label">
                                <input type="checkbox" :disabled="memberTrackIds.has(t.id)" :checked="selectedTracks.has(t.id)" @change="toggle(selectedTracks, t.id)" />
                                <i class="pi pi-volume-up" />
                                <span class="pick-name">{{ t.name }}</span>
                                <span v-if="formatDuration(t.duration_seconds)" class="pick-dur">{{ formatDuration(t.duration_seconds) }}</span>
                                <Tag v-if="memberTrackIds.has(t.id)" value="Added" severity="secondary" />
                            </label>
                        </li>
                    </ul>

                    <h4 v-if="candidates.media.length" class="picker-head">Photos &amp; videos</h4>
                    <div v-if="candidates.media.length" class="pick-grid">
                        <button
                            v-for="m in candidates.media"
                            :key="m.id"
                            type="button"
                            class="pick-tile"
                            :class="{ selected: selectedMedia.has(m.id), disabled: memberMediaIds.has(m.id) }"
                            :disabled="memberMediaIds.has(m.id)"
                            @click="toggle(selectedMedia, m.id)"
                        >
                            <img v-if="m.thumb_url" :src="m.thumb_url" :alt="m.name" loading="lazy" />
                            <div v-else class="placeholder"><i :class="m.kind === 'video' ? 'pi pi-video' : 'pi pi-image'" /></div>
                            <span v-if="selectedMedia.has(m.id)" class="check"><i class="pi pi-check" /></span>
                            <span v-if="memberMediaIds.has(m.id)" class="added-badge">Added</span>
                        </button>
                    </div>

                    <p v-if="!candidates.tracks.length && !candidates.media.length" class="picker-empty">
                        {{ query ? 'Nothing matches your search.' : 'You have no tracks or media to add yet.' }}
                    </p>
                </template>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showAdd = false" />
                <Button :label="selectedCount ? `Add ${selectedCount}` : 'Add'" icon="pi pi-check" :disabled="!selectedCount" @click="submitAdd" />
            </template>
        </Dialog>

        <!-- Media lightbox -->
        <Dialog :visible="!!lightbox" modal dismissableMask :showHeader="false" :style="{ width: 'auto', maxWidth: '92vw' }" :class="lightbox?.kind === 'video' ? 'lightbox-video-dialog' : ''" @update:visible="lightbox = null">
            <template v-if="lightbox">
                <video v-if="lightbox.kind === 'video'" :src="lightbox.url" controls autoplay class="lightbox-img lightbox-video" />
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
.tile-by { position: absolute; left: 0.35rem; bottom: 0.35rem; right: 0.35rem; font-size: 0.7rem; color: #fff; background: rgba(0,0,0,0.45); border-radius: 5px; padding: 0.1rem 0.35rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lightbox-img { max-width: 90vw; max-height: 85vh; display: block; }
.lightbox-video { transition: transform 0.2s ease; }
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
/* Add-items picker */
.picker { display: flex; flex-direction: column; gap: 0.75rem; }
.search { display: block; }
.picker-loading, .picker-empty { color: var(--p-text-muted-color); padding: 1rem 0; text-align: center; }
.picker-head { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--p-text-muted-color); margin: 0.5rem 0 0.25rem; }
.pick-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.pick-row { border-bottom: 1px solid var(--p-content-border-color); }
.pick-row.disabled { opacity: 0.55; }
.pick-label { display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.25rem; cursor: pointer; }
.pick-row.disabled .pick-label { cursor: default; }
.pick-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pick-dur { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-size: 0.85rem; color: var(--p-text-muted-color); }
.pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(6rem, 1fr)); gap: 0.5rem; }
.pick-tile { position: relative; aspect-ratio: 1; padding: 0; border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--p-surface-100); }
.pick-tile.selected { border-color: var(--p-primary-color); }
.pick-tile.disabled { opacity: 0.5; cursor: default; }
.pick-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pick-tile .placeholder { font-size: 1.5rem; }
.pick-tile .check { position: absolute; top: 0.25rem; right: 0.25rem; width: 1.3rem; height: 1.3rem; display: flex; align-items: center; justify-content: center; background: var(--p-primary-color); color: var(--p-primary-contrast-color); border-radius: 999px; font-size: 0.7rem; }
.pick-tile .added-badge { position: absolute; inset: auto 0 0 0; font-size: 0.65rem; color: #fff; background: rgba(0,0,0,0.55); padding: 0.1rem; text-align: center; }
</style>
