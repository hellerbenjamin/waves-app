<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Checkbox from 'primevue/checkbox';
import Button from 'primevue/button';
import Message from 'primevue/message';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h1 class="title">Log in</h1>

        <Message v-if="status" severity="success" :closable="false" class="status">{{ status }}</Message>

        <form @submit.prevent="submit" class="form">
            <div class="field">
                <label for="email">Email</label>
                <InputText
                    id="email"
                    type="email"
                    v-model="form.email"
                    :invalid="!!form.errors.email"
                    required
                    autofocus
                    autocomplete="username"
                    fluid
                />
                <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <Password
                    inputId="password"
                    v-model="form.password"
                    :invalid="!!form.errors.password"
                    :feedback="false"
                    toggleMask
                    required
                    autocomplete="current-password"
                    fluid
                />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </div>

            <div class="row">
                <div class="remember">
                    <Checkbox inputId="remember" v-model="form.remember" :binary="true" />
                    <label for="remember">Remember me</label>
                </div>
                <Link v-if="canResetPassword" :href="route('password.request')" class="link">Forgot password?</Link>
            </div>

            <Button type="submit" label="Log in" :loading="form.processing" fluid />

            <p class="muted">
                Don't have an account?
                <Link :href="route('register')" class="link">Register</Link>
            </p>
        </form>
    </GuestLayout>
</template>

<style scoped>
.title { font-size: 1.5rem; font-weight: 600; margin: 0 0 1.25rem; }
.status { margin-bottom: 1rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.field label { font-size: 0.875rem; font-weight: 500; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
.row { display: flex; align-items: center; justify-content: space-between; }
.remember { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.link { color: var(--p-primary-color); text-decoration: none; font-size: 0.875rem; }
.link:hover { text-decoration: underline; }
.muted { font-size: 0.875rem; color: var(--p-text-muted-color); text-align: center; margin: 0.5rem 0 0; }
</style>
