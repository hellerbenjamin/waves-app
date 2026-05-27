<script setup>
import { ref, watch, computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';

/**
 * Reorderable list + name input for combining tracks. The parent passes the
 * pre-selected tracks; we own only the drag order and the chosen name. On
 * submit we POST to /tracks/combine and emit `done` so the parent can refresh.
 *
 * Originals get deleted on success — the user opted into that — so this dialog
 * is the last chance to back out or reorder before that's committed.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    tracks: { type: Array, default: () => [] }, // [{ id, name, duration_seconds, ... }]
    eventId: { type: Number, default: null },
});

const emit = defineEmits(['update:visible', 'done']);

const ordered = ref([]);
const name = ref('');
const busy = ref(false);
const errorMessage = ref('');

// On open: clone the parent's list so drag reorder doesn't mutate the source
// array, and seed the name from the first track minus its extension plus a
// "+ N more" hint that makes the row easy to spot in the track list.
watch(() => props.visible, (open) => {
    if (!open) return;
    ordered.value = [...props.tracks];
    const base = (ordered.value[0]?.name || 'Combined').replace(/\.[^.]+$/, '');
    const extras = ordered.value.length - 1;
    name.value = extras > 0 ? `${base} (+${extras} more)` : base;
    errorMessage.value = '';
});

const totalDuration = computed(() => ordered.value.reduce((s, t) => s + (t.duration_seconds || 0), 0));

const formatDuration = (s) => {
    if (!s) return '—';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
};

const move = (i, delta) => {
    const j = i + delta;
    if (j < 0 || j >= ordered.value.length) return;
    const next = [...ordered.value];
    [next[i], next[j]] = [next[j], next[i]];
    ordered.value = next;
};

const remove = (i) => {
    ordered.value = ordered.value.filter((_, idx) => idx !== i);
};

// Native HTML5 drag to reorder. PrimeVue's OrderList is heavier and styled
// for two-pane pickers; this is a single column where a row swap is enough.
const dragIndex = ref(-1);
const onDragStart = (i, ev) => {
    dragIndex.value = i;
    ev.dataTransfer.effectAllowed = 'move';
};
const onDragOver = (ev) => { ev.preventDefault(); };
const onDrop = (i) => {
    const from = dragIndex.value;
    dragIndex.value = -1;
    if (from < 0 || from === i) return;
    const next = [...ordered.value];
    const [moved] = next.splice(from, 1);
    next.splice(i, 0, moved);
    ordered.value = next;
};

const csrfToken = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

const submit = async () => {
    const clean = name.value.trim();
    if (!clean || ordered.value.length < 2 || busy.value) return;
    busy.value = true;
    errorMessage.value = '';

    try {
        const res = await fetch(route('tracks.combine'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                track_ids: ordered.value.map((t) => t.id),
                name: clean,
                event_id: props.eventId,
            }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.message || `Combine failed (${res.status})`);
        }

        emit('done');
        emit('update:visible', false);
    } catch (e) {
        errorMessage.value = e.message || 'Combine failed';
    } finally {
        busy.value = false;
    }
};

const dialogVisible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});
</script>

<template>
    <Dialog
        v-model:visible="dialogVisible"
        modal
        :closable="!busy"
        :style="{ width: 'min(90vw, 48rem)' }"
        header="Combine tracks"
    >
        <div class="combine-dialog">
            <p class="combine-intro">
                Stitch these tracks into one new file in the order shown.
                The originals will be deleted once the combined track is created.
            </p>

            <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

            <div class="combine-list">
                <div
                    v-for="(t, i) in ordered"
                    :key="t.id"
                    class="combine-row"
                    draggable="true"
                    @dragstart="onDragStart(i, $event)"
                    @dragover="onDragOver"
                    @drop="onDrop(i)"
                >
                    <i class="pi pi-bars combine-grip" aria-label="Drag handle" />
                    <span class="combine-index">{{ i + 1 }}</span>
                    <span class="combine-name">{{ t.name }}</span>
                    <span class="combine-dur">{{ formatDuration(t.duration_seconds) }}</span>
                    <div class="combine-row-actions">
                        <Button
                            icon="pi pi-arrow-up"
                            text rounded size="small"
                            :disabled="i === 0"
                            aria-label="Move up"
                            @click="move(i, -1)"
                        />
                        <Button
                            icon="pi pi-arrow-down"
                            text rounded size="small"
                            :disabled="i === ordered.length - 1"
                            aria-label="Move down"
                            @click="move(i, 1)"
                        />
                        <Button
                            icon="pi pi-times"
                            text rounded size="small"
                            severity="secondary"
                            :disabled="ordered.length <= 2"
                            aria-label="Remove from combine"
                            @click="remove(i)"
                        />
                    </div>
                </div>
            </div>

            <div class="combine-total">
                Total length: <strong>{{ formatDuration(totalDuration) }}</strong>
            </div>

            <div class="combine-name-row">
                <label for="combined-name">New track name</label>
                <InputText id="combined-name" v-model="name" :disabled="busy" />
            </div>
        </div>

        <template #footer>
            <Button label="Cancel" text severity="secondary" :disabled="busy" @click="dialogVisible = false" />
            <Button
                label="Combine and delete originals"
                icon="pi pi-link"
                severity="primary"
                :loading="busy"
                :disabled="ordered.length < 2 || !name.trim()"
                @click="submit"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.combine-dialog { display: flex; flex-direction: column; gap: 1rem; }
.combine-intro { margin: 0; font-size: 0.875rem; color: var(--p-text-muted-color); }

.combine-list { display: flex; flex-direction: column; gap: 0.25rem; }
.combine-row {
    display: grid;
    grid-template-columns: 1rem 1.5rem 1fr auto auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--p-content-border-color);
    border-radius: 0.5rem;
    background: var(--p-content-background);
    cursor: grab;
}
.combine-row:active { cursor: grabbing; }
.combine-grip { color: var(--p-text-muted-color); font-size: 0.875rem; }
.combine-index { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.combine-name { font-size: 0.9375rem; }
.combine-dur { font-size: 0.8125rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.combine-row-actions { display: flex; gap: 0.125rem; }

.combine-total { font-size: 0.875rem; color: var(--p-text-muted-color); }
.combine-name-row { display: flex; flex-direction: column; gap: 0.375rem; }
.combine-name-row label { font-size: 0.875rem; font-weight: 500; }
.combine-name-row .p-inputtext { width: 100%; }
</style>
