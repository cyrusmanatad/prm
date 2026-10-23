<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AuthScreen from '../features/auth/AuthScreen.vue';
import type { AuthMode, AuthSubmission, FieldErrors } from '../types/foundation';

const props = withDefaults(
    defineProps<{
        mode: AuthMode;
        portal?: 'brand' | 'partner';
        email?: string;
        token?: string;
        status?: string;
        errors?: FieldErrors;
    }>(),
    { email: '', token: '', status: '', errors: () => ({}) },
);
const screen = ref<InstanceType<typeof AuthScreen>>();
const processing = ref(false);
const paths: Record<AuthMode, string> = {
    login: '/login',
    register: '/register',
    'forgot-password': '/forgot-password',
    'reset-password': '/reset-password',
    'verify-email': '/verify-email',
};
watch(
    () => props.mode,
    () => screen.value?.clearPasswords(),
);

function post(url: string, data: Record<string, string> = {}): void {
    if (processing.value) return;
    router.post(url, data, {
        onStart: () => {
            processing.value = true;
        },
        onFinish: () => {
            processing.value = false;
            screen.value?.clearPasswords();
        },
    });
}
function submit(submission: AuthSubmission): void {
    const { mode, ...fields } = submission;
    post(
        props.portal && ['login', 'register'].includes(mode) ? `/${props.portal}/${mode}` : paths[mode],
        mode === 'reset-password' ? { ...fields, token: props.token } : fields,
    );
}
function navigate(mode: AuthMode): void {
    if (!processing.value)
        router.get(props.portal && ['login', 'register'].includes(mode) ? `/${props.portal}/${mode}` : paths[mode]);
}
</script>

<template>
    <Head
        :title="
            mode === 'verify-email'
                ? 'Verify email'
                : mode === 'register'
                  ? 'Create account'
                  : mode === 'login'
                    ? 'Sign in'
                    : 'Password recovery'
        "
    />
    <AuthScreen
        ref="screen"
        :mode="mode"
        :portal="portal"
        :email="email"
        :errors="errors"
        :status="status"
        :processing="processing"
        @submit="submit"
        @navigate="navigate"
        @resend-verification="post('/email/verification-notification')"
        @logout="post('/logout')"
    />
</template>
