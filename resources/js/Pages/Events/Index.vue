<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import { typeLabel, typeOptions } from '@/lib/eventTypes';

const props = defineProps({
    events: { type: Array, required: true },
    types: { type: Array, default: () => [] },
});

const options = computed(() => typeOptions(props.types));

const showCreate = ref(false);
const form = useForm({
    name: '',
    type: 'live_show',
    event_date: null,
    location: '',
    description: '',
});

const openCreate = () => {
    form.reset();
    form.type = 'live_show';
    showCreate.value = true;
};

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            event_date: data.event_date ? toDateString(data.event_date) : null,
        }))
        .post(route('events.store'), {
            onSuccess: () => { showCreate.value = false; },
        });
};

const toDateString = (d) => {
    const date = d instanceof Date ? d : new Date(d);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const formatDate = (iso) => {
    if (!iso) return null;
    return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const open = (event) => router.visit(route('events.show', event.id));
</script>

<template>
    <Head title="Events" />

    <AuthenticatedLayout>
        <template #header>
            <div class="header-row">
                <h2 class="page-title">Events</h2>
                <Button icon="pi pi-plus" label="New event" @click="openCreate" />
            </div>
        </template>

        <Message v-if="!events.length" severity="info" :closable="false">
            No events yet. Create one to group tracks and collect photos and videos from a show, rehearsal, or session.
        </Message>

        <div v-else class="event-grid">
            <Card v-for="event in events" :key="event.id" class="event-card" @click="open(event)">
                <template #title>
                    <div class="card-title-row">
                        <span class="event-name">{{ event.name }}</span>
                        <i v-if="event.shared" class="pi pi-share-alt shared-icon" title="Shared" />
                    </div>
                </template>
                <template #subtitle>
                    <div class="meta-row">
                        <Tag :value="typeLabel(event.type)" severity="secondary" />
                        <span v-if="event.event_date" class="event-date">{{ formatDate(event.event_date) }}</span>
                    </div>
                </template>
                <template #content>
                    <div class="counts">
                        <span><i class="pi pi-volume-up" /> {{ event.tracks_count }} {{ event.tracks_count === 1 ? 'track' : 'tracks' }}</span>
                        <span><i class="pi pi-images" /> {{ event.media_count }} media</span>
                    </div>
                    <div v-if="event.location" class="location"><i class="pi pi-map-marker" /> {{ event.location }}</div>
                </template>
            </Card>
        </div>

        <Dialog v-model:visible="showCreate" modal header="New event" :style="{ width: '32rem' }">
            <div class="form">
                <div class="field">
                    <label for="ev-name">Name</label>
                    <InputText id="ev-name" v-model="form.name" autofocus :invalid="!!form.errors.name" />
                    <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="ev-type">Type</label>
                        <Select id="ev-type" v-model="form.type" :options="options" optionLabel="label" optionValue="value" />
                    </div>
                    <div class="field">
                        <label for="ev-date">Date</label>
                        <DatePicker id="ev-date" v-model="form.event_date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
                    </div>
                </div>
                <div class="field">
                    <label for="ev-loc">Location</label>
                    <InputText id="ev-loc" v-model="form.location" placeholder="Venue, studio, …" />
                </div>
                <div class="field">
                    <label for="ev-desc">Notes</label>
                    <Textarea id="ev-desc" v-model="form.description" rows="3" autoResize />
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
.event-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1.25rem; }
.event-card { cursor: pointer; transition: box-shadow 0.15s, transform 0.15s; }
.event-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.1); transform: translateY(-2px); }
.card-title-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.event-name { font-size: 1.05rem; }
.shared-icon { color: var(--p-primary-color); font-size: 0.9rem; }
.meta-row { display: flex; align-items: center; gap: 0.75rem; font-weight: 400; }
.event-date { font-size: 0.85rem; color: var(--p-text-muted-color); }
.counts { display: flex; gap: 1.25rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.counts i { margin-right: 0.35rem; }
.location { margin-top: 0.5rem; font-size: 0.85rem; color: var(--p-text-muted-color); }
.location i { margin-right: 0.35rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-select), .field :deep(.p-datepicker) { width: 100%; }
.field-row { display: flex; gap: 1rem; }
.err { color: var(--p-red-500); font-size: 0.8rem; }
</style>
