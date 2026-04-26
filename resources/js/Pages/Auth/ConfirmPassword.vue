<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import Password from 'primevue/password';
import Button from 'primevue/button';

const form = useForm({ password: '' });

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />
        <h1 class="title">Confirm password</h1>
        <p class="muted">This is a secure area. Confirm your password to continue.</p>

        <form @submit.prevent="submit" class="form">
            <div class="field">
                <label for="password">Password</label>
                <Password inputId="password" v-model="form.password" :invalid="!!form.errors.password" :feedback="false" toggleMask required autofocus autocomplete="current-password" fluid />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </div>

            <Button type="submit" label="Confirm" :loading="form.processing" fluid />
        </form>
    </GuestLayout>
</template>

<style scoped>
.title { font-size: 1.5rem; font-weight: 600; margin: 0 0 0.5rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; margin: 0 0 1.25rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.field label { font-size: 0.875rem; font-weight: 500; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
</style>
