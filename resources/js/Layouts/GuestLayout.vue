<script setup>
import { Link } from '@inertiajs/vue3';
import Card from 'primevue/card';
</script>

<template>
    <div class="guest-shell">
        <Link href="/" class="brand">Waves</Link>
        <Card class="guest-card">
            <template #content>
                <slot />
            </template>
        </Card>
    </div>
</template>

<style scoped>
.guest-shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    padding: 2rem 1rem;
}
.brand {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--p-primary-color);
    text-decoration: none;
    letter-spacing: -0.02em;
}
.guest-card {
    width: 100%;
    max-width: 28rem;
}
</style>
