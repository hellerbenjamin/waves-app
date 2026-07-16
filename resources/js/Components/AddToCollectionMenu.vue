<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import { useToast } from 'primevue/usetoast';
import { apiFetch } from '@/composables/useS3Upload';

// Drops an existing track or media item into one of the user's collections.
// The collection list is fetched lazily the first time the menu is opened.
const props = defineProps({
    type: { type: String, required: true }, // 'track' | 'media'
    ids: { type: Array, required: true },
    icon: { type: String, default: 'pi pi-folder-plus' },
    label: { type: String, default: '' },
    text: { type: Boolean, default: false },
    rounded: { type: Boolean, default: false },
    size: { type: String, default: null },
    severity: { type: String, default: 'secondary' },
});

const toast = useToast();
const menu = ref(null);
const items = ref([]);
const loaded = ref(false);

const addTo = (collection) => router.post(
    route('collections.items.attach', collection.id),
    { type: props.type, ids: props.ids },
    {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.add({ severity: 'success', summary: `Added to ${collection.name}`, life: 2500 }),
        onError: () => toast.add({ severity: 'error', summary: 'Could not add', life: 3000 }),
    },
);

const buildItems = (collections) => collections.length
    ? collections.map((c) => ({ label: c.name, icon: 'pi pi-folder', command: () => addTo(c) }))
    : [{ label: 'No collections yet', disabled: true }];

const toggle = async (event) => {
    if (!loaded.value) items.value = [{ label: 'Loading…', disabled: true }];
    menu.value.toggle(event);
    if (loaded.value) return;
    const res = await apiFetch(route('collections.list'));
    const data = res.ok ? await res.json() : { collections: [] };
    items.value = buildItems(data.collections);
    loaded.value = true;
};
</script>

<template>
    <span class="add-to-collection">
        <Button
            :icon="icon"
            :label="label"
            :text="text"
            :rounded="rounded"
            :size="size"
            :severity="severity"
            aria-label="Add to collection"
            aria-haspopup="true"
            @click="toggle"
        />
        <Menu ref="menu" :model="items" :popup="true" />
    </span>
</template>

<style scoped>
.add-to-collection { display: inline-flex; line-height: 0; }
</style>
