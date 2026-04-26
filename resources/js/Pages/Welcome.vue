<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});
</script>

<template>
    <Head title="Welcome" />
    <div class="welcome">
        <header class="hero">
            <h1>Waves</h1>
            <p class="tagline">A multi-band waveform player for your audio in the cloud.</p>

            <nav v-if="canLogin" class="actions">
                <Link v-if="$page.props.auth.user" :href="route('dashboard')">
                    <Button label="Open dashboard" />
                </Link>
                <template v-else>
                    <Link :href="route('login')">
                        <Button label="Log in" outlined />
                    </Link>
                    <Link v-if="canRegister" :href="route('register')">
                        <Button label="Register" />
                    </Link>
                </template>
            </nav>
        </header>

        <footer>Laravel v{{ laravelVersion }} · PHP v{{ phpVersion }}</footer>
    </div>
</template>

<style scoped>
.welcome {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
}
.hero h1 {
    font-size: 4rem;
    margin: 0 0 0.5rem;
    color: var(--p-primary-color);
    letter-spacing: -0.04em;
}
.tagline {
    font-size: 1.125rem;
    color: var(--p-text-muted-color);
    margin: 0 0 2rem;
    max-width: 32rem;
}
.actions { display: flex; gap: 0.75rem; justify-content: center; }
footer {
    margin-top: 4rem;
    font-size: 0.8125rem;
    color: var(--p-text-muted-color);
}
</style>
