<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Message from 'primevue/message';
import { typeLabel } from '@/lib/eventTypes';

const props = defineProps({
    name: { type: String, required: true },
    shareToken: { type: String, required: true },
    events: { type: Array, default: () => [] },
});

const heading = computed(() => {
    const n = props.name?.trim();
    if (!n) return 'Events';
    return /s$/i.test(n) ? `${n}' events` : `${n}'s events`;
});

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

const eventUrl = (event) => route('profile.shared.event', [props.shareToken, event.id]);
</script>

<template>
    <Head :title="heading" />

    <PublicLayout>
        <template #header>
            <h1 class="page-title">{{ heading }}</h1>
        </template>

        <Message v-if="!events.length" severity="info" :closable="false">
            No events to show yet.
        </Message>

        <div v-else class="timeline">
            <section v-for="group in groups" :key="group.key" class="month">
                <h3 class="month-label">{{ group.label }}</h3>
                <ul class="event-list">
                    <li v-for="event in group.events" :key="event.id" class="event-row">
                        <Link :href="eventUrl(event)" class="event-link">
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
                                </div>
                                <div class="row-sub">
                                    <span>{{ typeLabel(event.type) }}</span>
                                    <span v-if="event.location"> · {{ event.location }}</span>
                                </div>
                            </div>
                            <div class="row-counts">
                                <span class="count" :aria-label="`${event.tracks_count} tracks`">
                                    <i class="pi pi-volume-up" aria-hidden="true" /> {{ event.tracks_count }}
                                </span>
                                <span class="count" :aria-label="`${event.media_count} photos and videos`">
                                    <i class="pi pi-images" aria-hidden="true" /> {{ event.media_count }}
                                </span>
                            </div>
                            <i class="pi pi-chevron-right chevron" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </PublicLayout>
</template>

<style scoped>
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.timeline { display: flex; flex-direction: column; gap: 1.75rem; }
.month-label {
    font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--p-text-muted-color); margin: 0 0 0.6rem; padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--p-content-border-color);
}
.event-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.event-row {
    border-radius: 0.5rem; transition: background-color 0.12s;
    border-bottom: 1px solid var(--p-content-border-color);
}
.event-row:last-child { border-bottom: none; }
.event-row:hover { background: var(--p-content-hover-background); }
.event-link {
    display: flex; align-items: center; gap: 1rem; padding: 0.75rem 0.5rem;
    text-decoration: none; color: inherit; border-radius: 0.5rem;
}
.event-link:focus-visible { outline: 2px solid var(--p-primary-color); outline-offset: -2px; }
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
.row-sub { font-size: 0.85rem; color: var(--p-text-muted-color); margin-top: 0.15rem; }
.row-counts { flex: 0 0 auto; display: flex; gap: 1rem; font-size: 0.9rem; color: var(--p-text-muted-color); }
.count { display: inline-flex; align-items: center; }
.row-counts i { margin-right: 0.3rem; }
.chevron { flex: 0 0 auto; color: var(--p-text-muted-color); font-size: 0.8rem; }
</style>
