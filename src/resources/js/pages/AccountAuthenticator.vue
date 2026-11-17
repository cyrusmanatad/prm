<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatorScreen from '../features/auth/AuthenticatorScreen.vue';
import type { AccountSummary, FieldErrors } from '../types/foundation';

withDefaults(
    defineProps<{
        enrolled: boolean;
        verified: boolean;
        required: boolean;
        operator: boolean;
        recoveryRemaining?: number | null;
        continueTo?: string | null;
        secret?: string | null;
        recoveryCodes?: string[] | null;
        errors?: FieldErrors;
    }>(),
    { recoveryRemaining: null, continueTo: null, secret: null, recoveryCodes: null, errors: () => ({}) },
);
const account = computed(() => usePage<{ account?: AccountSummary | null }>().props.account);
const screen = ref<InstanceType<typeof AuthenticatorScreen>>();
const processing = ref(false);

function send(method: 'post' | 'delete', url: string, data: Record<string, string> = {}): void {
    if (processing.value) return;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        // Keep the chosen form (recovery code, replace, new codes) when the server returns an error.
        preserveState: true,
        onStart: () => {
            processing.value = true;
        },
        onFinish: () => {
            processing.value = false;
            screen.value?.clear();
        },
    });
}
</script>

<template>
    <Head title="Authenticator" />
    <AuthenticatorScreen
        ref="screen"
        :account-name="account?.name ?? ''"
        :account-email="account?.email ?? ''"
        :enrolled="enrolled"
        :verified="verified"
        :required="required"
        :operator="operator"
        :recovery-remaining="recoveryRemaining"
        :continue-to="continueTo"
        :secret="secret"
        :recovery-codes="recoveryCodes"
        :errors="errors"
        :processing="processing"
        @enroll="send('post', '/account/authenticator/enroll', { password: $event })"
        @cancel="send('delete', '/account/authenticator/enroll')"
        @verify="send('post', '/account/authenticator/verify', { code: $event })"
        @recover="send('post', '/account/authenticator/recover', { recovery_code: $event })"
        @regenerate="(password, code) => send('post', '/account/authenticator/recovery-codes', { password, code })"
        @logout="send('post', '/logout')"
    />
</template>
