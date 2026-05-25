<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Menubar from 'primevue/menubar';
import Menu from 'primevue/menu';
import Button from 'primevue/button';
import Avatar from 'primevue/avatar';

const page = usePage();
const userMenu = ref(null);

const navItems = [
    { label: 'Tracks', icon: 'pi pi-list', command: () => router.visit(route('tracks.index')) },
    { label: 'Events', icon: 'pi pi-calendar', command: () => router.visit(route('events.index')) },
];

const userMenuItems = [
    { label: 'Profile', icon: 'pi pi-user', command: () => router.visit(route('profile.edit')) },
    { separator: true },
    { label: 'Log Out', icon: 'pi pi-sign-out', command: () => router.post(route('logout')) },
];

const initials = (name) => name?.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase() || '?';
</script>

<template>
    <div class="app-shell">
        <Menubar :model="navItems" class="app-nav">
            <template #start>
                <Link :href="route('tracks.index')" class="brand">Waves</Link>
            </template>
            <template #end>
                <Button text @click="(e) => userMenu.toggle(e)" :aria-label="page.props.auth.user.name" aria-haspopup="true" aria-controls="user-menu">
                    <Avatar :label="initials(page.props.auth.user.name)" shape="circle" />
                    <span class="user-name">{{ page.props.auth.user.name }}</span>
                    <i class="pi pi-chevron-down" />
                </Button>
                <Menu id="user-menu" ref="userMenu" :model="userMenuItems" :popup="true" />
            </template>
        </Menubar>

        <header v-if="$slots.header" class="page-header">
            <div class="container">
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
.app-shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
.app-nav {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
    border-top: 0;
}
.brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--p-primary-color);
    text-decoration: none;
    margin-right: 1.5rem;
    letter-spacing: -0.02em;
}
.user-name {
    margin: 0 0.5rem;
    font-weight: 500;
}
.page-header {
    background: var(--p-surface-0);
    border-bottom: 1px solid var(--p-surface-200);
    padding: 1.5rem 0;
}
.page-main {
    flex: 1;
    padding: 2rem 0;
}
.container {
    max-width: 80rem;
    margin: 0 auto;
    padding: 0 1.5rem;
}
</style>
