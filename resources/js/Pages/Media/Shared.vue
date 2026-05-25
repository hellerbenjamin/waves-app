<script setup>
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    media: { type: Object, required: true },
});
</script>

<template>
    <Head :title="media.name" />

    <PublicLayout>
        <template #header>
            <h2 class="page-title">{{ media.name }}</h2>
        </template>

        <div class="viewer">
            <img v-if="media.kind === 'image'" :src="media.url" :alt="media.name" class="image" />
            <video v-else :src="media.url" controls autoplay class="video" />
        </div>
    </PublicLayout>
</template>

<style scoped>
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.viewer { display: flex; justify-content: center; }
.image { max-width: 100%; max-height: 80vh; border-radius: 8px; }
.video { max-width: 100%; max-height: 80vh; border-radius: 8px; background: #000; }
</style>
