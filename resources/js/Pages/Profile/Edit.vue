<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';
import Card from 'primevue/card';

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="page-title">Profile</h2>
        </template>

        <div class="stack">
            <Card>
                <template #content>
                    <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
                </template>
            </Card>
            <Card>
                <template #content>
                    <UpdatePasswordForm />
                </template>
            </Card>
            <Card>
                <template #content>
                    <DeleteUserForm />
                </template>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.page-title { font-size: 1.25rem; font-weight: 600; margin: 0; }
.stack { display: flex; flex-direction: column; gap: 1.5rem; max-width: 42rem; }
</style>
