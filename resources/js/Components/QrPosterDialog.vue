<script setup>
import { ref, watch } from 'vue';
import QRCode from 'qrcode';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const props = defineProps({
    visible: { type: Boolean, default: false },
    url: { type: String, default: '' },
    eventName: { type: String, default: '' },
    // The invite label (e.g. "Audience"), shown as a tag when present.
    label: { type: String, default: null },
});

const emit = defineEmits(['update:visible']);

const caption = 'Scan to send your photos & videos — no account needed';

// PNG data URL of the QR; the same image powers display, download, and print.
const dataUrl = ref('');

// Regenerate whenever the dialog opens against a (new) link.
watch(
    () => [props.visible, props.url],
    async ([visible, url]) => {
        if (!visible || !url) return;
        dataUrl.value = await QRCode.toDataURL(url, { width: 512, margin: 2 });
    },
    { immediate: true },
);

// Filename like "open-mic-audience-qr.png".
const downloadName = () => {
    const slug = [props.eventName, props.label]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return `${slug || 'upload-link'}-qr.png`;
};

// Print just the poster: a fresh window sidesteps the app's screen styles.
const print = () => {
    if (!dataUrl.value) return;
    const w = window.open('', '_blank', 'width=600,height=800');
    if (!w) return;
    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
    ));
    w.document.write(`<!doctype html><html><head><title>${esc(props.eventName)}</title>
        <style>
            body { font-family: system-ui, sans-serif; text-align: center; margin: 0; padding: 3rem 1.5rem; }
            h1 { font-size: 1.8rem; margin: 0 0 0.25rem; }
            .label { color: #666; font-size: 1rem; margin: 0 0 1.5rem; }
            img { width: 80vw; max-width: 22rem; height: auto; }
            p.caption { font-size: 1.1rem; margin: 1.5rem auto 0; max-width: 22rem; }
        </style></head><body>
        <h1>${esc(props.eventName)}</h1>
        ${props.label ? `<p class="label">${esc(props.label)}</p>` : ''}
        <img src="${dataUrl.value}" alt="QR code" />
        <p class="caption">${esc(caption)}</p>
        </body></html>`);
    w.document.close();
    w.focus();
    // Give the image a tick to decode before the print dialog opens.
    w.onload = () => w.print();
};
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="eventName"
        :style="{ width: '24rem' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="qr-poster">
            <Tag v-if="label" :value="label" severity="secondary" />
            <img v-if="dataUrl" :src="dataUrl" alt="QR code for the upload link" class="qr-img" />
            <p class="caption">{{ caption }}</p>
        </div>
        <template #footer>
            <Button icon="pi pi-print" label="Print" severity="secondary" @click="print" />
            <a :href="dataUrl" :download="downloadName()" class="dl">
                <Button icon="pi pi-download" label="Download PNG" />
            </a>
        </template>
    </Dialog>
</template>

<style scoped>
.qr-poster { display: flex; flex-direction: column; align-items: center; gap: 1rem; text-align: center; }
.qr-img { width: 16rem; height: 16rem; image-rendering: pixelated; }
.caption { color: var(--p-text-muted-color); font-size: 0.95rem; margin: 0; max-width: 18rem; }
.dl { text-decoration: none; }
</style>
