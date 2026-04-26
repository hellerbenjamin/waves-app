<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import Message from 'primevue/message';

defineProps({ status: String });

const form = useForm({ email: '' });

const submit = () => form.post(route('password.email'));
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <h1 class="title">Forgot password</h1>
        <p class="muted">Enter your email and we'll send a reset link.</p>

        <Message v-if="status" severity="success" :closable="false" class="status">{{ status }}</Message>

        <form @submit.prevent="submit" class="form">
            <div class="field">
                <label for="email">Email</label>
                <InputText id="email" type="email" v-model="form.email" :invalid="!!form.errors.email" required autofocus autocomplete="username" fluid />
                <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
            </div>

            <Button type="submit" label="Email reset link" :loading="form.processing" fluid />
        </form>
    </GuestLayout>
</template>

<style scoped>
.title { font-size: 1.5rem; font-weight: 600; margin: 0 0 0.5rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; margin: 0 0 1.25rem; }
.status { margin-bottom: 1rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.field label { font-size: 0.875rem; font-weight: 500; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
</style>
