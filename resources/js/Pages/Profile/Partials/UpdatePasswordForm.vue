<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Password from 'primevue/password';
import Button from 'primevue/button';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.$el?.querySelector('input')?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.$el?.querySelector('input')?.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header class="header">
            <h2>Update password</h2>
            <p>Use a long, random password to keep your account secure.</p>
        </header>

        <form @submit.prevent="updatePassword" class="form">
            <div class="field">
                <label for="current_password">Current password</label>
                <Password inputId="current_password" ref="currentPasswordInput" v-model="form.current_password" :invalid="!!form.errors.current_password" :feedback="false" toggleMask autocomplete="current-password" fluid />
                <small v-if="form.errors.current_password" class="error">{{ form.errors.current_password }}</small>
            </div>

            <div class="field">
                <label for="password">New password</label>
                <Password inputId="password" ref="passwordInput" v-model="form.password" :invalid="!!form.errors.password" toggleMask autocomplete="new-password" fluid />
                <small v-if="form.errors.password" class="error">{{ form.errors.password }}</small>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <Password inputId="password_confirmation" v-model="form.password_confirmation" :invalid="!!form.errors.password_confirmation" :feedback="false" toggleMask autocomplete="new-password" fluid />
                <small v-if="form.errors.password_confirmation" class="error">{{ form.errors.password_confirmation }}</small>
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
.actions { display: flex; align-items: center; gap: 1rem; }
.muted { color: var(--p-text-muted-color); font-size: 0.875rem; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
