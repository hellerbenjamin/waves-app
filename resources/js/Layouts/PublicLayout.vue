<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="public-shell">
        <header class="public-nav">
            <Link href="/" class="brand">Waves</Link>
            <div v-if="$slots.header" class="public-header-slot">
                <slot name="header" />
            </div>
        </header>

        <main class="page-main">
            <div class="container">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.public-shell { min-height: 100vh; display: flex; flex-direction: column; }
.public-nav {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1rem 1.5rem;
    background: var(--p-surface-0);
    border-bottom: 1px solid var(--p-surface-200);
}
.brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--p-primary-color);
    text-decoration: none;
    letter-spacing: -0.02em;
}
.public-header-slot { flex: 1; min-width: 0; }
.page-main { flex: 1; padding: 2rem 0; }
.container { max-width: 80rem; margin: 0 auto; padding: 0 1.5rem; }
</style>
