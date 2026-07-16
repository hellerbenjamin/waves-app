<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';
import Card from 'primevue/card';
import Message from 'primevue/message';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import Toast from 'primevue/toast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import SplitBeforeUploadDialog from '@/Components/SplitBeforeUploadDialog.vue';
import StitchedSplitDialog from '@/Components/StitchedSplitDialog.vue';
import AddToCollectionMenu from '@/Components/AddToCollectionMenu.vue';
import { useSplitBeforeUpload } from '@/composables/useSplitBeforeUpload.js';
import { useStitchedSplit } from '@/composables/useStitchedSplit.js';
import { useChannelUpload } from '@/composables/useChannelUpload.js';
import { isOpusEncodeSupported } from '@/lib/opusEncode.js';

defineProps({
    tracks: { type: Array, required: true },
    events: { type: Array, default: () => [] },
});

// Assign (or clear, when eventId is null) a track's event, then refresh the
// table so the new grouping is reflected.
const assignEvent = async (track, eventId) => {
    try {
        const res = await fetch(route('tracks.update', track.id), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            body: JSON.stringify({ event_id: eventId }),
        });
        if (!res.ok) throw new Error(`failed (${res.status})`);
        router.reload({ only: ['tracks'], preserveScroll: true });
    } catch (err) {
        toast.add({ severity: 'error', summary: 'Could not move track', detail: err.message, life: 4000 });
    }
};

const fileInput = ref(null);
const uploads = ref([]);
const toast = useToast();
const confirm = useConfirm();

const formatBytes = (n) => {
    if (n == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
};

const formatDuration = (s) => {
    if (s == null) return '—';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
};

const pickFile = () => fileInput.value?.click();

// Hard ceiling on the source WAV we'll read in the browser. 50 GB.
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024 * 1024;

const { uploadWavFile } = useChannelUpload();

const onFileSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    for (const file of files) addUpload(file);
};

const addUpload = (file) => {
    if (!/\.wav$/i.test(file.name)) {
        toast.add({ severity: 'error', summary: 'Invalid file', detail: `${file.name}: only .wav files are allowed`, life: 4000 });
        return;
    }
    if (file.size > MAX_UPLOAD_BYTES) {
        toast.add({ severity: 'error', summary: 'File too large', detail: `${file.name}: ${formatBytes(file.size)} exceeds the ${formatBytes(MAX_UPLOAD_BYTES)} limit`, life: 5000 });
        return;
    }
    if (!isOpusEncodeSupported()) {
        toast.add({ severity: 'error', summary: 'Unsupported browser', detail: 'Audio upload needs a desktop browser with WebCodecs (recent Chrome, Edge, Firefox, or Safari).', life: 6000 });
        return;
    }

    // Long-WAV interception lives in the composable: it reads the header and
    // either opens the split dialog or falls through to `queueForUpload`.
    enqueueWithSplit(file);
};

// Encode a (multi-channel) WAV blob's channels to Opus in the browser, then
// upload them as one track. Both the direct picker and the split/stitch dialog
// outputs funnel through here — the dialog blobs are zero-copy in-browser WAVs
// that get encoded, never uploaded as WAV.
const queueForUpload = async (file) => {
    // Starts 'queued'; the first encode progress event flips it to 'encoding'
    // (uploads are serialised, so a batch waits its turn here).
    const entry = ref({ name: file.name.replace(/\.[^.]+$/, ''), progress: 0, status: 'queued' });
    uploads.value.push(entry.value);

    try {
        await uploadWavFile(file, {
            name: file.name,
            eventId: null,
            onProgress: (p) => {
                entry.value.progress = Math.round(p * 100);
                entry.value.status = p < 0.7 ? 'encoding' : 'uploading';
            },
        });
        entry.value.status = 'done';
        uploads.value = uploads.value.filter((u) => u !== entry.value);
        toast.add({ severity: 'success', summary: 'Uploaded', detail: entry.value.name, life: 3000 });
        router.reload({ only: ['tracks'], preserveScroll: true });
    } catch (err) {
        entry.value.status = 'error';
        toast.add({ severity: 'error', summary: 'Upload failed', detail: `${entry.value.name}: ${err?.message || 'error'}`, life: 5000 });
    }
};

const {
    splitDialogVisible,
    pendingSplitFile,
    enqueueWithSplit,
    onSplitCommit,
    onSplitUploadWhole,
    onSplitCancel,
} = useSplitBeforeUpload(queueForUpload);

const {
    stitchedDialogVisible,
    pendingStitchedFiles,
    openStitchedSplit,
    onStitchedCommit,
    onStitchedCancel,
} = useStitchedSplit(queueForUpload);

// Separate picker for multi-file recordings (mixer chunks that should be
// stitched and re-split). Validates WAV extension and size before opening the
// dialog so a stray pick fails fast.
const stitchedInput = ref(null);
const pickStitched = () => stitchedInput.value?.click();
const onStitchedSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (files.length < 2) {
        toast.add({ severity: 'warn', summary: 'Pick multiple files', detail: 'Select two or more WAV files to stitch and split.', life: 4000 });
        return;
    }
    for (const f of files) {
        if (!/\.wav$/i.test(f.name)) {
            toast.add({ severity: 'error', summary: 'Invalid file', detail: `${f.name}: only .wav files are allowed`, life: 4000 });
            return;
        }
        if (f.size > MAX_UPLOAD_BYTES) {
            toast.add({ severity: 'error', summary: 'File too large', detail: `${f.name}: ${formatBytes(f.size)} exceeds the ${formatBytes(MAX_UPLOAD_BYTES)} limit`, life: 5000 });
            return;
        }
    }
    openStitchedSplit(files);
};

const confirmDelete = (track) => {
    confirm.require({
        message: `Delete "${track.name}"? This cannot be undone.`,
        header: 'Delete track',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(route('tracks.destroy', track.id), { preserveScroll: true }),
    });
};

// Rename uses the JSON update endpoint (not an Inertia visit), then refreshes
// just the tracks prop so the table reflects the new name.
const renameTarget = ref(null);
const renameValue = ref('');
const renameBusy = ref(false);
const showRenameDialog = ref(false);

const openRename = (track) => {
    renameTarget.value = track;
    renameValue.value = track.name;
    showRenameDialog.value = true;
};

const submitRename = async () => {
    const name = renameValue.value.trim();
    if (!name || renameBusy.value) return;
    renameBusy.value = true;
    try {
        const res = await fetch(route('tracks.update', renameTarget.value.id), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            body: JSON.stringify({ original_name: name }),
        });
        if (!res.ok) throw new Error(`rename failed (${res.status})`);
        showRenameDialog.value = false;
        router.reload({ only: ['tracks'], preserveScroll: true });
    } catch (err) {
        toast.add({ severity: 'error', summary: 'Rename failed', detail: err.message, life: 4000 });
    } finally {
        renameBusy.value = false;
    }
};
</script>

<template>
    <Head title="Tracks" />
    <Toast />
    <ConfirmDialog />
    <SplitBeforeUploadDialog
        v-model:visible="splitDialogVisible"
        :file="pendingSplitFile"
        @commit="onSplitCommit"
        @upload-whole="onSplitUploadWhole"
        @cancel="onSplitCancel"
    />
    <StitchedSplitDialog
        v-model:visible="stitchedDialogVisible"
        :files="pendingStitchedFiles"
        @commit="onStitchedCommit"
        @cancel="onStitchedCancel"
    />
    <AuthenticatedLayout>
        <template #header>
            <div class="header-row">
                <h2 class="page-title">Tracks</h2>
                <div class="header-actions">
                    <Button icon="pi pi-upload" label="Upload .wav" @click="pickFile" />
                    <Button icon="pi pi-objects-column" label="Stitch & split" severity="secondary" outlined @click="pickStitched" />
                </div>
                <input ref="fileInput" type="file" accept=".wav,audio/wav" multiple style="display:none" @change="onFileSelected" />
                <input ref="stitchedInput" type="file" accept=".wav,audio/wav" multiple style="display:none" @change="onStitchedSelected" />
            </div>
        </template>

        <div class="stack">
            <Card v-if="uploads.length">
                <template #content>
                    <div class="upload-list">
                        <div v-for="u in uploads" :key="u.name" class="upload-row">
                            <div class="upload-name">
                                <i class="pi pi-cloud-upload" />
                                <span>{{ u.name }}</span>
                                <Tag :value="u.status" />
                            </div>
                            <ProgressBar :value="u.progress" />
                        </div>
                    </div>
                </template>
            </Card>

            <Message v-if="!tracks.length" severity="info" :closable="false">
                No tracks yet. Click "Upload .wav" to add one.
            </Message>

            <Card v-else>
                <template #content>
                    <DataTable
                        :value="tracks"
                        data-key="id"
                        stripedRows
                    >
                        <Column header="Name">
                            <template #body="{ data }">
                                <Link :href="route('tracks.show', data.id)" class="track-link">{{ data.name }}</Link>
                            </template>
                        </Column>
                        <Column header="Status">
                            <template #body="{ data }">
                                <Tag v-if="data.ready" severity="success" value="Ready" />
                                <Tag v-else severity="warn" value="Processing" />
                            </template>
                        </Column>
                        <Column header="Duration">
                            <template #body="{ data }">{{ formatDuration(data.duration_seconds) }}</template>
                        </Column>
                        <Column header="Size">
                            <template #body="{ data }">{{ formatBytes(data.size) }}</template>
                        </Column>
                        <Column header="Event" style="width: 14rem">
                            <template #body="{ data }">
                                <Select
                                    :modelValue="data.event_id"
                                    :options="events"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="—"
                                    showClear
                                    size="small"
                                    class="event-select"
                                    @update:modelValue="(val) => assignEvent(data, val)"
                                />
                            </template>
                        </Column>
                        <Column header="" style="width: 14rem; text-align: right">
                            <template #body="{ data }">
                                <AddToCollectionMenu type="track" :ids="[data.id]" text rounded severity="secondary" />
                                <Button icon="pi pi-pencil" severity="secondary" text rounded aria-label="Rename" @click="openRename(data)" />
                                <Button icon="pi pi-trash" severity="danger" text rounded aria-label="Delete" @click="confirmDelete(data)" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <Dialog v-model:visible="showRenameDialog" modal header="Rename track" :style="{ width: '26rem' }">
            <div class="rename-dialog">
                <label for="rename-name">Track name</label>
                <InputText id="rename-name" v-model="renameValue" autofocus @keyup.enter="submitRename" />
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showRenameDialog = false" />
                <Button label="Save" icon="pi pi-check" :loading="renameBusy" :disabled="!renameValue.trim()" @click="submitRename" />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.header-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.header-actions { display: flex; gap: 0.5rem; }
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.stack { display: flex; flex-direction: column; gap: 1.5rem; }
.upload-list { display: flex; flex-direction: column; gap: 0.875rem; }
.upload-row { display: flex; flex-direction: column; gap: 0.375rem; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.event-select { width: 100%; }
.track-link { color: var(--p-primary-color); text-decoration: none; font-weight: 500; }
.track-link:hover { text-decoration: underline; }
.rename-dialog { display: flex; flex-direction: column; gap: 0.5rem; }
.rename-dialog label { font-size: 0.875rem; font-weight: 500; }
.rename-dialog .p-inputtext { width: 100%; }
</style>
