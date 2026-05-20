<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';
import Card from 'primevue/card';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import Toast from 'primevue/toast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';

defineProps({
    tracks: { type: Array, required: true },
});

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

const onFileSelected = async (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    for (const file of files) {
        await uploadOne(file);
    }
};

const uploadOne = async (file) => {
    if (!/\.wav$/i.test(file.name)) {
        toast.add({ severity: 'error', summary: 'Invalid file', detail: `${file.name}: only .wav files are allowed`, life: 4000 });
        return;
    }

    const entry = ref({ name: file.name, progress: 0, status: 'requesting' });
    uploads.value.push(entry.value);

    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            || document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];

        const initRes = await fetch(route('tracks.upload-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            body: JSON.stringify({
                filename: file.name,
                size: file.size,
                content_type: file.type || 'audio/wav',
            }),
        });

        if (!initRes.ok) throw new Error(`init failed (${initRes.status})`);
        const { url, headers, s3_key } = await initRes.json();

        entry.value.status = 'uploading';

        await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url, true);
            for (const [k, v] of Object.entries(headers || {})) xhr.setRequestHeader(k, v);
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) entry.value.progress = Math.round((e.loaded / e.total) * 100);
            };
            xhr.onload = () => (xhr.status >= 200 && xhr.status < 300) ? resolve() : reject(new Error(`S3 upload ${xhr.status}`));
            xhr.onerror = () => reject(new Error('Network error'));
            xhr.send(file);
        });

        entry.value.status = 'finalizing';
        entry.value.progress = 100;

        await new Promise((resolve, reject) => {
            router.post(route('tracks.store'), {
                s3_key,
                original_name: file.name,
                mime: file.type || 'audio/wav',
                size: file.size,
            }, {
                preserveScroll: true,
                onSuccess: resolve,
                onError: reject,
            });
        });

        entry.value.status = 'done';
        toast.add({ severity: 'success', summary: 'Uploaded', detail: file.name, life: 3000 });
        uploads.value = uploads.value.filter(u => u !== entry.value);
    } catch (err) {
        entry.value.status = 'error';
        toast.add({ severity: 'error', summary: 'Upload failed', detail: `${file.name}: ${err.message}`, life: 5000 });
    }
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
</script>

<template>
    <Head title="Tracks" />
    <Toast />
    <ConfirmDialog />

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
                    <DataTable :value="tracks" stripedRows>
                        <Column field="name" header="Name" />
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
                        <Column header="" style="width: 8rem; text-align: right">
                            <template #body="{ data }">
                                <Button icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.header-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.stack { display: flex; flex-direction: column; gap: 1.5rem; }
.upload-list { display: flex; flex-direction: column; gap: 0.875rem; }
.upload-row { display: flex; flex-direction: column; gap: 0.375rem; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
</style>
