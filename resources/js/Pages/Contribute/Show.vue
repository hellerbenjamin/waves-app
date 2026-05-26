<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Message from 'primevue/message';
import InputText from 'primevue/inputtext';
import ProgressBar from 'primevue/progressbar';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { useS3Upload } from '@/composables/useS3Upload';

const props = defineProps({
    invite: { type: Object, required: true },
    event: { type: Object, required: true },
});

const toast = useToast();

// Free-text attribution; sent with every file the contributor adds this session.
const name = ref('');
const fileInput = ref(null);
// Count of successful uploads, to swap copy to a friendly "thanks" once they start.
const doneCount = ref(0);

const formatDate = (iso) => iso
    ? new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
    : null;

const ALLOWED = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
];

const { uploads, addFiles } = useS3Upload({
    routes: {
        uploadUrl: route('contribute.upload-url', props.invite.token),
        multipartCreate: route('contribute.multipart.create', props.invite.token),
        multipartSign: route('contribute.multipart.sign', props.invite.token),
        multipartComplete: route('contribute.multipart.complete', props.invite.token),
        multipartAbort: route('contribute.multipart.abort', props.invite.token),
        cleanup: route('contribute.cleanup', props.invite.token),
    },
    initBody: (file) => ({ filename: file.name, size: file.size, content_type: file.type }),
    validate: (file) => ALLOWED.includes(file.type) ? null : 'unsupported file type',
    finalize: (file, key) => new Promise((resolve, reject) => {
        router.post(route('contribute.store', props.invite.token), {
            s3_key: key,
            original_name: file.name,
            mime: file.type,
            size: file.size,
            contributor_name: name.value.trim() || null,
        }, { preserveScroll: true, preserveState: true, onSuccess: resolve, onError: reject });
    }),
    onUploaded: (file) => {
        doneCount.value++;
        toast.add({ severity: 'success', summary: 'Uploaded', detail: file.name, life: 3000 });
    },
    onError: (file, message) => toast.add({ severity: 'error', summary: 'Upload failed', detail: `${file?.name}: ${message}`, life: 5000 }),
});

const pickFiles = () => fileInput.value?.click();
const onSelected = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    addFiles(files);
};
</script>

<template>
    <Head :title="`Add to ${event.name}`" />
    <Toast />

    <PublicLayout>
        <div class="contribute">
            <header class="intro">
                <Tag v-if="invite.label" :value="invite.label" severity="secondary" />
                <h1>{{ event.name }}</h1>
                <p v-if="event.event_date || event.location" class="meta">
                    <span v-if="event.event_date">{{ formatDate(event.event_date) }}</span>
                    <span v-if="event.location"><i class="pi pi-map-marker" /> {{ event.location }}</span>
                </p>
            </header>

            <Message v-if="!invite.active" severity="warn" :closable="false">
                This upload link is no longer active. Ask the organiser for a fresh one.
            </Message>

            <template v-else>
                <Card class="panel">
                    <template #content>
                        <h2>Add your photos &amp; videos</h2>
                        <p class="hint">They go straight to the organiser of this event. You don't need an account.</p>

                        <div class="field">
                            <label for="c-name">Your name <span class="optional">(optional)</span></label>
                            <InputText id="c-name" v-model="name" placeholder="So they know who to thank" />
                        </div>

                        <Button class="pick" icon="pi pi-camera" label="Choose photos or videos" size="large" @click="pickFiles" />
                        <input ref="fileInput" type="file" accept="image/*,video/*" capture="environment" multiple style="display:none" @change="onSelected" />
                    </template>
                </Card>

                <Card v-if="uploads.length" class="panel">
                    <template #content>
                        <div v-for="u in uploads" :key="u.name" class="upload-row">
                            <div class="upload-name"><i class="pi pi-cloud-upload" /> <span>{{ u.name }}</span> <Tag :value="u.status" /></div>
                            <ProgressBar :value="u.progress" />
                        </div>
                    </template>
                </Card>

                <Message v-if="doneCount && !uploads.length" severity="success" :closable="false">
                    Thanks! {{ doneCount }} {{ doneCount === 1 ? 'file' : 'files' }} uploaded. Add more any time.
                </Message>
            </template>
        </div>
    </PublicLayout>
</template>

<style scoped>
.contribute { max-width: 36rem; margin: 0 auto; display: flex; flex-direction: column; gap: 1.25rem; }
.intro { text-align: center; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
.intro h1 { font-size: 1.6rem; font-weight: 700; margin: 0; }
.meta { display: flex; gap: 1rem; font-size: 0.9rem; color: var(--p-text-muted-color); margin: 0; }
.meta i { margin-right: 0.25rem; }
.panel h2 { font-size: 1.1rem; font-weight: 600; margin: 0 0 0.25rem; }
.hint { color: var(--p-text-muted-color); font-size: 0.9rem; margin: 0 0 1.25rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1.25rem; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext) { width: 100%; }
.optional { font-weight: 400; color: var(--p-text-muted-color); }
.pick { width: 100%; }
.upload-row { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.75rem; }
.upload-row:last-child { margin-bottom: 0; }
.upload-name { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
</style>
