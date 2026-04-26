<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <h1 class="title">Create your account</h1>

        <form @submit.prevent="submit" class="form">
            <div class="field">
                <label for="name">Name</label>
                <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" required autofocus autocomplete="name" fluid />
                <small v-if="form.errors.name" class="error">{{ form.errors.name }}</small>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <InputText id="email" type="email" v-model="form.email" :invalid="!!form.errors.email" required autocomplete="username" fluid />
                <small v-if="form.errors.email" class="error">{{ form.errors.email }}</small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <Password inputId="password" v-model="form.password" :invalid="!!form.errors.password" toggleMask required autocomplete="new-password" fluid />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <Password inputId="password_confirmation" v-model="form.password_confirmation" :invalid="!!form.errors.password_confirmation" :feedback="false" toggleMask required autocomplete="new-password" fluid />
                <small v-if="form.errors.password_confirmation" class="error">{{ form.errors.password_confirmation }}</small>
            </div>

            <Button type="submit" label="Register" :loading="form.processing" fluid />

            <p class="muted">
                Already registered?
                <Link :href="route('login')" class="link">Log in</Link>
            </p>
        </form>
    </GuestLayout>
</template>

<style scoped>
.title { font-size: 1.5rem; font-weight: 600; margin: 0 0 1.25rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.field label { font-size: 0.875rem; font-weight: 500; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
.link { color: var(--p-primary-color); text-decoration: none; }
.link:hover { text-decoration: underline; }
.muted { font-size: 0.875rem; color: var(--p-text-muted-color); text-align: center; margin: 0.5rem 0 0; }
</style>
