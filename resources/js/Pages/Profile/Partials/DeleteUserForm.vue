<script setup>
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Password from 'primevue/password';

const showDialog = ref(false);
const passwordInput = ref(null);

const form = useForm({ password: '' });

const open = () => {
    showDialog.value = true;
    nextTick(() => passwordInput.value?.$el?.querySelector('input')?.focus());
};

const close = () => {
    showDialog.value = false;
    form.clearErrors();
    form.reset();
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: close,
        onError: () => passwordInput.value?.$el?.querySelector('input')?.focus(),
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <section>
        <header class="header">
            <h2>Delete account</h2>
            <p>Once your account is deleted, all of its data will be permanently lost. Download anything you want to keep first.</p>
        </header>

        <Button severity="danger" label="Delete account" @click="open" />

        <Dialog v-model:visible="showDialog" modal header="Delete account?" :style="{ width: '28rem' }" @hide="close">
            <p class="muted">Enter your password to permanently delete your account.</p>
            <div class="field">
                <Password inputId="confirm_password" ref="passwordInput" v-model="form.password" :invalid="!!form.errors.password" :feedback="false" toggleMask placeholder="Password" fluid @keyup.enter="deleteUser" />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </div>
            <template #footer>
                <Button label="Cancel" text @click="close" />
                <Button severity="danger" label="Delete account" :loading="form.processing" @click="deleteUser" />
            </template>
        </Dialog>
    </section>
</template>

<style scoped>
.header h2 { margin: 0 0 0.25rem; font-size: 1.0625rem; font-weight: 600; }
.header p { margin: 0 0 1.25rem; color: var(--p-text-muted-color); font-size: 0.875rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; margin: 0 0 1rem; }
.field { display: flex; flex-direction: column; gap: 0.375rem; }
.error { color: var(--p-red-500); font-size: 0.8125rem; }
</style>
