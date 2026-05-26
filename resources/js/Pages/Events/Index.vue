<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
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

// Group the (already date-sorted) events under month/year headers; undated last.
const groups = computed(() => {
    const out = [];
    const byKey = new Map();
    for (const ev of props.events) {
        const key = ev.event_date ? ev.event_date.slice(0, 7) : 'undated';
        let group = byKey.get(key);
        if (!group) {
            group = { key, label: monthLabel(ev.event_date), events: [] };
            byKey.set(key, group);
            out.push(group);
        }
        group.events.push(ev);
    }
    return out;
});

const monthLabel = (iso) =>
    iso
        ? new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
        : 'No date';

const asDate = (iso) => new Date(iso + 'T00:00:00');
const weekday = (iso) => asDate(iso).toLocaleDateString(undefined, { weekday: 'short' });
const dayNum = (iso) => asDate(iso).getDate();

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

        <div v-else class="timeline">
            <section v-for="group in groups" :key="group.key" class="month">
                <h3 class="month-label">{{ group.label }}</h3>
                <ul class="event-list">
                    <li
                        v-for="event in group.events"
                        :key="event.id"
                        class="event-row"
                        tabindex="0"
                        @click="open(event)"
                        @keydown.enter="open(event)"
                    >
                        <div class="date-chip" :class="{ undated: !event.event_date }">
                            <template v-if="event.event_date">
                                <span class="weekday">{{ weekday(event.event_date) }}</span>
                                <span class="day">{{ dayNum(event.event_date) }}</span>
                            </template>
                            <i v-else class="pi pi-calendar-times" />
                        </div>
                        <div class="row-body">
                            <div class="row-top">
                                <span class="event-name">{{ event.name }}</span>
                                <i v-if="event.shared" class="pi pi-share-alt shared-icon" title="Shared" />
                            </div>
                            <div class="row-sub">
                                <span>{{ typeLabel(event.type) }}</span>
                                <span v-if="event.location"> · {{ event.location }}</span>
                            </div>
                        </div>
                        <div class="row-counts">
                            <span
                                class="count"
                                :title="`${event.tracks_count} ${event.tracks_count === 1 ? 'track' : 'tracks'}`"
                                :aria-label="`${event.tracks_count} ${event.tracks_count === 1 ? 'track' : 'tracks'}`"
                            >
                                <i class="pi pi-volume-up" aria-hidden="true" /> {{ event.tracks_count }}
                            </span>
                            <span
                                class="count"
                                :title="`${event.media_count} photos &amp; videos`"
                                :aria-label="`${event.media_count} photos and videos`"
                            >
                                <i class="pi pi-images" aria-hidden="true" /> {{ event.media_count }}
                            </span>
                        </div>
                        <i class="pi pi-chevron-right chevron" />
                    </li>
                </ul>
            </section>
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
.timeline { display: flex; flex-direction: column; gap: 1.75rem; }
.month-label {
    font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--p-text-muted-color); margin: 0 0 0.6rem; padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--p-content-border-color);
}
.event-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.event-row {
    display: flex; align-items: center; gap: 1rem; padding: 0.75rem 0.5rem;
    cursor: pointer; border-radius: 0.5rem; transition: background-color 0.12s;
    border-bottom: 1px solid var(--p-content-border-color);
}
.event-row:last-child { border-bottom: none; }
.event-row:hover { background: var(--p-content-hover-background); }
.event-row:focus-visible { outline: 2px solid var(--p-primary-color); outline-offset: -2px; }
.date-chip {
    flex: 0 0 auto; width: 3rem; text-align: center; line-height: 1.1;
    color: var(--p-text-color);
}
.date-chip .weekday { display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--p-text-muted-color); }
.date-chip .day { display: block; font-size: 1.35rem; font-weight: 600; }
.date-chip.undated { color: var(--p-text-muted-color); font-size: 1.1rem; }
.row-body { flex: 1 1 auto; min-width: 0; }
.row-top { display: flex; align-items: center; gap: 0.5rem; }
.event-name { font-size: 1.02rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shared-icon { color: var(--p-primary-color); font-size: 0.85rem; }
.row-sub { font-size: 0.85rem; color: var(--p-text-muted-color); margin-top: 0.15rem; }
.row-counts { flex: 0 0 auto; display: flex; gap: 1rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.count { display: inline-flex; align-items: center; }
.row-counts i { margin-right: 0.3rem; }
.chevron { flex: 0 0 auto; color: var(--p-text-muted-color); font-size: 0.8rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.4rem; flex: 1; }
.field label { font-size: 0.85rem; font-weight: 500; }
.field :deep(.p-inputtext), .field :deep(.p-select), .field :deep(.p-datepicker) { width: 100%; }
.field-row { display: flex; gap: 1rem; }
.err { color: var(--p-red-500); font-size: 0.8rem; }
</style>
