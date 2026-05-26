<script setup>
import { ref } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { apiFetch } from '@/composables/useS3Upload';

const props = defineProps({
    shareUrl: { type: String, default: null },
});

const toast = useToast();
const shareUrl = ref(props.shareUrl);
const busy = ref(false);

const enable = async () => {
    busy.value = true;
    const res = await apiFetch(route('profile.share'), { method: 'POST' });
    busy.value = false;
    if (!res.ok) return toast.add({ severity: 'error', summary: 'Could not create link', life: 4000 });
    shareUrl.value = (await res.json()).share_url;
    copy();
};

const copy = () => {
    navigator.clipboard?.writeText(shareUrl.value);
    toast.add({ severity: 'success', summary: 'Link copied', detail: shareUrl.value, life: 3000 });
};

const disable = async () => {
    busy.value = true;
    const res = await apiFetch(route('profile.unshare'), { method: 'DELETE' });
    busy.value = false;
    if (res.ok) { shareUrl.value = null; toast.add({ severity: 'info', summary: 'Sharing disabled', life: 2500 }); }
};
</script>

<template>
    <section>
        <Toast />
        <header class="header">
            <h2>Share all events</h2>
            <p>
                Create one public link that lists every event you own — including
                ones you add later. Anyone with the link can browse your events and
                play their tracks, photos, and videos, but can't change anything.
            </p>
        </header>

        <div v-if="shareUrl" class="link-row">
            <InputText :modelValue="shareUrl" readonly fluid @focus="(e) => e.target.select()" />
            <Button icon="pi pi-copy" label="Copy" severity="secondary" @click="copy" />
            <Button icon="pi pi-times" label="Stop sharing" severity="secondary" text :loading="busy" @click="disable" />
        </div>
        <div v-else class="actions">
            <Button icon="pi pi-share-alt" label="Create share link" :loading="busy" @click="enable" />
        </div>
    </section>
</template>

<style scoped>
.header h2 { margin: 0 0 0.25rem; font-size: 1.0625rem; font-weight: 600; }
.header p { margin: 0 0 1.25rem; color: var(--p-text-muted-color); font-size: 0.875rem; max-width: 40rem; }
.link-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.link-row :deep(.p-inputtext) { flex: 1 1 18rem; min-width: 0; }
.actions { display: flex; align-items: center; gap: 1rem; }
</style>
