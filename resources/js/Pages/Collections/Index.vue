<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';

const props = defineProps({
    collections: { type: Array, required: true },
});

const showCreate = ref(false);
const form = useForm({
    name: '',
    description: '',
});

const openCreate = () => {
    form.reset();
    showCreate.value = true;
};

const submit = () => {
    form.post(route('collections.store'), {
        onSuccess: () => { showCreate.value = false; },
    });
};

const open = (collection) => router.visit(route('collections.show', collection.id));
</script>

<template>
    <Head title="Collections" />

    <AuthenticatedLayout>
        <template #header>
            <div class="header-row">
                <h2 class="page-title">Collections</h2>
                <Button icon="pi pi-plus" label="New collection" @click="openCreate" />
            </div>
        </template>

        <Message v-if="!collections.length" severity="info" :closable="false">
            No collections yet. Create one to curate photos and videos into a shareable set.
        </Message>

        <ul v-else class="collection-list">
            <li
                v-for="collection in collections"
                :key="collection.id"
                class="collection-row"
                tabindex="0"
                @click="open(collection)"
                @keydown.enter="open(collection)"
            >
                <div class="cover"><i class="pi pi-images" /></div>
                <div class="row-body">
                    <div class="row-top">
                        <span class="collection-name">{{ collection.name }}</span>
                        <i v-if="collection.shared" class="pi pi-share-alt shared-icon" title="Shared" />
                    </div>
                    <div v-if="collection.description" class="row-sub">{{ collection.description }}</div>
                </div>
                <div class="row-counts">
                    <span
                        class="count"
                        :aria-label="`${collection.media_count} photos and videos`"
                    >
                        <i class="pi pi-images" aria-hidden="true" /> {{ collection.media_count }}
                    </span>
                </div>
                <i class="pi pi-chevron-right chevron" />
            </li>
        </ul>

        <Dialog v-model:visible="showCreate" modal header="New collection" :style="{ width: '32rem' }">
            <div class="form">
                <div class="field">
                    <label for="c-name">Name</label>
                    <InputText id="c-name" v-model="form.name" autofocus :invalid="!!form.errors.name" />
                    <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
                </div>
                <div class="field">
                    <label for="c-desc">Notes <span class="optional">(optional)</span></label>
                    <Textarea id="c-desc" v-model="form.description" rows="3" autoResize />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="showCreate = false" />
                <Button label="Create" icon="pi pi-check" :loading="form.processing" :disabled="!form.name.trim()" @click="submit" />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.header-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.collection-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.collection-row {
    display: flex; align-items: center; gap: 1rem; padding: 0.75rem 0.5rem;
    cursor: pointer; border-radius: 0.5rem; transition: background-color 0.12s;
    border-bottom: 1px solid var(--p-content-border-color);
}
.collection-row:last-child { border-bottom: none; }
.collection-row:hover { background: var(--p-content-hover-background); }
.collection-row:focus-visible { outline: 2px solid var(--p-primary-color); outline-offset: -2px; }
.cover {
    flex: 0 0 auto; width: 3rem; height: 3rem; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; background: var(--p-surface-100); color: var(--p-text-muted-color); font-size: 1.25rem;
}
.row-body { flex: 1 1 auto; min-width: 0; }
.row-top { display: flex; align-items: center; gap: 0.5rem; }
.collection-name { font-size: 1.02rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shared-icon { color: var(--p-primary-color); font-size: 0.85rem; }
.row-sub { font-size: 0.85rem; color: var(--p-text-muted-color); margin-top: 0.15rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.row-counts { flex: 0 0 auto; display: flex; gap: 1rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.count { display: inline-flex; align-items: center; }
.row-counts i { margin-right: 0.3rem; }
.chevron { flex: 0 0 auto; color: var(--p-text-muted-color); font-size: 0.8rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-textarea) { width: 100%; }
.optional { font-weight: 400; color: var(--p-text-muted-color); }
.err { color: var(--p-red-500); font-size: 0.8rem; }
</style>
