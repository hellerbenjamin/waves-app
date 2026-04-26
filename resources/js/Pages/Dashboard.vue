<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Card from 'primevue/card';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="page-title">Dashboard</h2>
        </template>

        <Card>
            <template #content>
                <p>You're logged in.</p>
            </template>
        </Card>
    </AuthenticatedLayout>
</template>

<style scoped>
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
</style>
