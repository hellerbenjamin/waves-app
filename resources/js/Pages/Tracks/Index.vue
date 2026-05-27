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
import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import SplitBeforeUploadDialog from '@/Components/SplitBeforeUploadDialog.vue';
import CombineTracksDialog from '@/Components/CombineTracksDialog.vue';
import { useSplitBeforeUpload } from '@/composables/useSplitBeforeUpload.js';

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

// Hard ceiling on upload size, mirrored server-side. 50 GB.
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024 * 1024;

const csrfToken = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

const apiFetch = (url, { method = 'GET', body } = {}) => fetch(url, {
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

// uppy file id -> the reactive row we render in the upload list.
const uploadEntries = new Map();
// uppy file id -> the storage key minted when the upload was initiated.
const uploadKeys = new Map();

// Uppy + the unified AWS S3 plugin: small files take a single presigned PUT,
// anything large is uploaded as multipart so multi-gigabyte files don't ride on
// one request. All signing/finalising goes through our own endpoints (no
// Companion server); the browser PUTs the bytes straight to R2.
const uppy = new Uppy({ autoProceed: true })
    .use(AwsS3, {
        // Below ~100 MB a single PUT is simpler and cheaper than multipart.
        shouldUseMultipart: (file) => file.size > 100 * 1024 * 1024,
        // Keep part count under S3's 10,000 cap even at 50 GB, never below the
        // 5 MB minimum part size.
        getChunkSize: (file) => Math.max(5 * 1024 * 1024, Math.ceil(file.size / 9000)),

        async getUploadParameters(file) {
            const res = await apiFetch(route('tracks.upload-url'), {
                method: 'POST',
                body: { filename: file.name, size: file.size, content_type: file.type || 'audio/wav' },
            });
            if (!res.ok) throw new Error(`init failed (${res.status})`);
            const data = await res.json();
            uploadKeys.set(file.id, data.s3_key);
            return { method: 'PUT', url: data.url, headers: data.headers || {} };
        },

        async createMultipartUpload(file) {
            const res = await apiFetch(route('tracks.multipart.create'), {
                method: 'POST',
                body: { filename: file.name, size: file.size, content_type: file.type || 'audio/wav' },
            });
            if (!res.ok) throw new Error(`init failed (${res.status})`);
            const data = await res.json();
            uploadKeys.set(file.id, data.key);
            return { uploadId: data.uploadId, key: data.key };
        },

        async signPart(file, { uploadId, key, partNumber }) {
            const url = `${route('tracks.multipart.sign')}?key=${encodeURIComponent(key)}`
                + `&uploadId=${encodeURIComponent(uploadId)}&partNumber=${partNumber}`;
            const res = await apiFetch(url);
            if (!res.ok) throw new Error(`sign failed (${res.status})`);
            return { url: (await res.json()).url };
        },

        async completeMultipartUpload(file, { uploadId, key, parts }) {
            const res = await apiFetch(route('tracks.multipart.complete'), {
                method: 'POST',
                body: { key, uploadId, parts },
            });
            if (!res.ok) throw new Error(`complete failed (${res.status})`);
            return { location: (await res.json()).location };
        },

        async abortMultipartUpload(file, { uploadId, key }) {
            await apiFetch(route('tracks.multipart.abort'), { method: 'POST', body: { key, uploadId } });
        },
    });

uppy.on('upload-progress', (file, progress) => {
    const entry = uploadEntries.get(file.id);
    if (!entry) return;
    entry.status = 'uploading';
    if (progress.bytesTotal) entry.progress = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
});

// The bytes are in storage; create the Track row so the rest of the app sees it.
uppy.on('upload-success', async (file) => {
    const entry = uploadEntries.get(file.id);
    const key = uploadKeys.get(file.id);
    if (entry) { entry.status = 'finalizing'; entry.progress = 100; }

    try {
        await new Promise((resolve, reject) => {
            router.post(route('tracks.store'), {
                s3_key: key,
                original_name: file.name,
                mime: file.type || 'audio/wav',
                size: file.size,
            }, { preserveScroll: true, preserveState: true, onSuccess: resolve, onError: reject });
        });

        if (entry) {
            entry.status = 'done';
            uploads.value = uploads.value.filter(u => u !== entry);
        }
        toast.add({ severity: 'success', summary: 'Uploaded', detail: file.name, life: 3000 });
    } catch (err) {
        if (entry) entry.status = 'error';
        // The bytes reached the bucket but no Track row was created; delete the
        // orphaned object so a failed finalise doesn't leak storage. Best effort.
        if (key) apiFetch(route('tracks.cleanup'), { method: 'POST', body: { key } }).catch(() => {});
        toast.add({ severity: 'error', summary: 'Upload failed', detail: `${file.name}: finalize failed`, life: 5000 });
    } finally {
        uploadEntries.delete(file.id);
        uploadKeys.delete(file.id);
        uppy.removeFile(file.id);
    }
});

uppy.on('upload-error', (file, error) => {
    const entry = uploadEntries.get(file?.id);
    if (entry) entry.status = 'error';
    toast.add({ severity: 'error', summary: 'Upload failed', detail: `${file?.name}: ${error?.message || 'error'}`, life: 5000 });
    uploadEntries.delete(file?.id);
    uploadKeys.delete(file?.id);
});

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

    // Long-WAV interception lives in the composable: it reads the header and
    // either opens the split dialog or falls through to `queueForUpload`.
    enqueueWithSplit(file);
};

// Push a File (or Blob-wrapped-as-File) at Uppy. Bypasses the duration check,
// so it's also the entry point for the split dialog's segment outputs.
const queueForUpload = (file) => {
    const entry = ref({ name: file.name, progress: 0, status: 'queued' });
    uploads.value.push(entry.value);

    try {
        const id = uppy.addFile({ name: file.name, type: file.type || 'audio/wav', data: file });
        uploadEntries.set(id, entry.value);
    } catch (err) {
        entry.value.status = 'error';
        toast.add({ severity: 'error', summary: 'Upload failed', detail: `${file.name}: ${err?.message || 'could not queue'}`, life: 5000 });
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

// Combine: DataTable's built-in multi-select drives a "Combine N" action that
// opens the shared dialog. Selection is cleared after a successful combine.
const selectedTracks = ref([]);
const showCombineDialog = ref(false);

const openCombine = () => {
    if (selectedTracks.value.length < 2) return;
    showCombineDialog.value = true;
};

const onCombineDone = () => {
    selectedTracks.value = [];
    router.reload({ only: ['tracks'], preserveScroll: true });
    toast.add({
        severity: 'success',
        summary: 'Combine queued',
        detail: 'The combined track will appear once the job finishes.',
        life: 4000,
    });
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
    <CombineTracksDialog
        v-model:visible="showCombineDialog"
        :tracks="selectedTracks"
        @done="onCombineDone"
    />

    <AuthenticatedLayout>
        <template #header>
            <div class="header-row">
                <h2 class="page-title">Tracks</h2>
                <Button icon="pi pi-upload" label="Upload .wav" @click="pickFile" />
                <input ref="fileInput" type="file" accept=".wav,audio/wav" multiple style="display:none" @change="onFileSelected" />
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
                    <div v-if="selectedTracks.length >= 2" class="bulk-actions">
                        <span>{{ selectedTracks.length }} tracks selected</span>
                        <Button label="Combine…" icon="pi pi-link" size="small" @click="openCombine" />
                        <Button label="Clear" text size="small" severity="secondary" @click="selectedTracks = []" />
                    </div>
                    <DataTable
                        v-model:selection="selectedTracks"
                        :value="tracks"
                        data-key="id"
                        selection-mode="multiple"
                        :meta-key-selection="false"
                        stripedRows
                    >
                        <Column selection-mode="multiple" header-style="width:3rem" />
                        <Column header="Name">
                            <template #body="{ data }">
                                <Link :href="route('tracks.show', data.id)" class="track-link">{{ data.name }}</Link>
                            </template>
                        </Column>
                        <Column header="Status">
                            <template #body="{ data }">
                                <Tag v-if="data.peaks_ready" severity="success" value="Ready" />
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
                        <Column header="" style="width: 11rem; text-align: right">
                            <template #body="{ data }">
                                <Button
                                    icon="pi pi-download"
                                    severity="secondary"
                                    text
                                    rounded
                                    aria-label="Download"
                                    :as="'a'"
                                    :href="route('tracks.download', data.id)"
                                />
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
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.stack { display: flex; flex-direction: column; gap: 1.5rem; }
.upload-list { display: flex; flex-direction: column; gap: 0.875rem; }
.upload-row { display: flex; flex-direction: column; gap: 0.375rem; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.event-select { width: 100%; }
.bulk-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.75rem;
    border-radius: 0.5rem;
    background: var(--p-highlight-background);
    color: var(--p-highlight-color);
    font-size: 0.875rem;
}
.track-link { color: var(--p-primary-color); text-decoration: none; font-weight: 500; }
.track-link:hover { text-decoration: underline; }
.rename-dialog { display: flex; flex-direction: column; gap: 0.5rem; }
.rename-dialog label { font-size: 0.875rem; font-weight: 500; }
.rename-dialog .p-inputtext { width: 100%; }
</style>
