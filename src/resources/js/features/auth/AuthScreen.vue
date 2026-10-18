<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FoundationShell from '../../layouts/FoundationShell.vue';
import type { AuthMode, AuthSubmission, FieldErrors } from '../../types/foundation';

const props = withDefaults(defineProps<{
    mode: AuthMode;
    email?: string;
    errors?: FieldErrors;
    processing?: boolean;
    status?: string;
}>(), { email: '', errors: () => ({}), processing: false, status: '' });
const emit = defineEmits<{
    submit: [submission: AuthSubmission];
    navigate: [destination: AuthMode];
    resendVerification: [];
    logout: [];
}>();
const root = ref<HTMLElement>();
const fields = reactive({ name: '', email: props.email, password: '', password_confirmation: '' });
const content = computed(() => ({
    login: { title: 'Welcome back.', description: 'Sign in to your WeavDash workspace.', action: 'Sign in' },
    register: { title: 'Make room for what’s next.', description: 'Create your account to start a workspace.', action: 'Create account' },
    'forgot-password': { title: 'Find your way back.', description: 'Enter your email and we’ll send a password reset link.', action: 'Send reset link' },
    'reset-password': { title: 'A fresh start.', description: 'Choose a new password for your account.', action: 'Reset password' },
    'verify-email': { title: 'Check your inbox.', description: 'Verify your email before creating or joining a workspace.', action: 'Resend verification email' },
})[props.mode]);
const needsPassword = computed(() => ['login', 'register', 'reset-password'].includes(props.mode));
const needsConfirmation = computed(() => ['register', 'reset-password'].includes(props.mode));

function clearPasswords(): void {
    fields.password = '';
    fields.password_confirmation = '';
}
watch(() => props.mode, clearPasswords);
watch(() => props.email, (email) => { fields.email = email; });
useValidationFocus(root, () => props.errors, () => !props.processing);

function submit(): void {
    if (props.processing) return;
    if (props.mode === 'verify-email') {
        emit('resendVerification');
    } else if (props.mode === 'login') {
        emit('submit', { mode: props.mode, email: fields.email, password: fields.password });
    } else if (props.mode === 'forgot-password') {
        emit('submit', { mode: props.mode, email: fields.email });
    } else if (props.mode === 'register') {
        emit('submit', { mode: props.mode, ...fields });
    } else {
        emit('submit', {
            mode: props.mode, email: fields.email,
            password: fields.password, password_confirmation: fields.password_confirmation,
        });
    }
}
defineExpose({ clearPasswords });
</script>

<template>
    <FoundationShell>
        <div ref="root" class="wd-auth-grid">
            <aside class="wd-introduction" aria-labelledby="introduction-title">
                <span class="wd-eyebrow">A little structure. More possibility.</span>
                <h2 id="introduction-title">Keep your next<br>move in view.</h2>
                <p>A space for your brand relationships, from the first hello to the next collaboration.</p>
                <svg class="wd-thread" viewBox="0 0 360 180" fill="none" aria-hidden="true">
                    <path d="M-20 120C60-40 90 220 160 80S280 10 380 120" stroke="#8FD0B8" stroke-width="35" />
                    <path d="M-20 150C60-10 90 250 160 110S280 40 380 150" stroke="#E3C79A" stroke-width="12" />
                </svg>
            </aside>
            <section class="wd-card wd-auth-card" aria-labelledby="auth-title">
                <span class="wd-eyebrow">Your account</span>
                <h1 id="auth-title">{{ content.title }}</h1>
                <p class="wd-description">{{ content.description }}</p>
                <p v-if="status" class="wd-status" role="status">{{ status }}</p>
                <p v-if="errors.general" class="wd-field-error" role="alert">{{ errors.general }}</p>
                <form :aria-busy="processing" @submit.prevent="submit">
                    <fieldset :disabled="processing" class="wd-fields">
                        <legend class="wd-sr-only">{{ content.title }}</legend>
                        <template v-if="mode !== 'verify-email'">
                            <FormField v-if="mode === 'register'" id="name" v-model="fields.name" label="Your name" autocomplete="name" :maxlength="120" :error="errors.name" />
                            <FormField id="email" v-model="fields.email" label="Email address" type="email" autocomplete="username" :maxlength="255" :error="errors.email" />
                            <FormField v-if="needsPassword" id="password" v-model="fields.password" label="Password" type="password" :autocomplete="needsConfirmation ? 'new-password' : 'current-password'" :minlength="needsConfirmation ? 12 : undefined" :hint="needsConfirmation ? 'At least 12 characters, including letters and numbers.' : undefined" :error="errors.password" />
                            <FormField v-if="needsConfirmation" id="password_confirmation" v-model="fields.password_confirmation" label="Confirm password" type="password" autocomplete="new-password" :error="errors.password_confirmation" />
                        </template>
                        <p v-else-if="email" class="wd-verification-email">We sent a link to <strong>{{ email }}</strong>.</p>
                        <Button type="submit" class="wd-button wd-button-primary">{{ processing ? 'Please wait…' : content.action }}</Button>
                    </fieldset>
                </form>
                <nav class="wd-auth-navigation" aria-label="Account options">
                    <template v-if="mode === 'login'">
                        <button type="button" class="wd-link" :disabled="processing" @click="emit('navigate', 'forgot-password')">Forgot password?</button>
                        <p>New here? <button type="button" class="wd-link" :disabled="processing" @click="emit('navigate', 'register')">Create an account</button></p>
                    </template>
                    <button v-else-if="mode === 'verify-email'" type="button" class="wd-link" :disabled="processing" @click="emit('logout')">Sign out</button>
                    <button v-else type="button" class="wd-link" :disabled="processing" @click="emit('navigate', 'login')">Back to sign in</button>
                </nav>
            </section>
        </div>
    </FoundationShell>
</template>
