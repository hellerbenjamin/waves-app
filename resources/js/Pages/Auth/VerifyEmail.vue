<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Message from 'primevue/message';

const props = defineProps({ status: String });
const form = useForm({});
const submit = () => form.post(route('verification.send'));
const sent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />
        <h1 class="title">Verify your email</h1>
        <p class="muted">
            Thanks for signing up! Click the link we sent to your email to continue.
            If you didn't get it, we'll send another.
        </p>

        <Message v-if="sent" severity="success" :closable="false" class="status">
            A new verification link has been sent.
        </Message>

        <form @submit.prevent="submit" class="actions">
            <Button type="submit" label="Resend verification email" :loading="form.processing" />
            <Link :href="route('logout')" method="post" as="button" class="link">Log out</Link>
        </form>
    </GuestLayout>
</template>

<style scoped>
.title { font-size: 1.5rem; font-weight: 600; margin: 0 0 0.5rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; margin: 0 0 1rem; }
.status { margin-bottom: 1rem; }
.actions { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.link { background: none; border: 0; cursor: pointer; color: var(--p-text-muted-color); text-decoration: underline; font-size: 0.875rem; }
</style>
