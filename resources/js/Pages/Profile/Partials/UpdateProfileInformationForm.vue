<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import Message from 'primevue/message';

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header class="header">
            <h2>Profile information</h2>
            <p>Update your account's profile information and email address.</p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="form">
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

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="verify">
                <p>
                    Your email address is unverified.
                    <Link :href="route('verification.send')" method="post" as="button" class="link">Resend verification email.</Link>
                </p>
                <Message v-if="status === 'verification-link-sent'" severity="success" :closable="false">
                    A new verification link has been sent.
                </Message>
            </div>

            <div class="actions">
                <Button type="submit" label="Save" :loading="form.processing" />
                <Transition name="fade">
                    <span v-if="form.recentlySuccessful" class="muted">Saved.</span>
                </Transition>
            </div>
        </form>
    </section>
</template>

<style scoped>
.header h2 { margin: 0 0 0.25rem; font-size: 1.0625rem; font-weight: 600; }
.header p { margin: 0 0 1.25rem; color: var(--p-text-muted-color); font-size: 0.875rem; }
.form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.field label { font-size: 0.875rem; font-weight: 500; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
.verify { font-size: 0.875rem; }
.link { background: none; border: 0; padding: 0; cursor: pointer; color: var(--p-primary-color); text-decoration: underline; }
.actions { display: flex; align-items: center; gap: 1rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
