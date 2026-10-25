<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import FormField from './FormField.vue';
import { Button } from './ui/button';
defineProps<{ accountName: string; busy: boolean; pending: boolean; error?: string }>();
const password = defineModel<string>({ required: true });
const emit = defineEmits<{ revoke: [] }>();
</script>
<template>
    <details class="wd-card wd-security-card">
        <summary>Account security</summary>
        <p class="wd-description">
            Sign out every session, including this one. Enter your current password to continue.
        </p>
        <form
            :aria-busy="pending"
            @submit.prevent="
                confirmAction(
                    'Sign out all sessions',
                    `Sign out every session for ${accountName}, including this one? You will need to sign in again.`,
                    () => emit('revoke'),
                    $event,
                    { tone: 'danger' },
                )
            "
        >
            <fieldset class="wd-fields" :disabled="busy">
                <legend class="wd-sr-only">Revoke account sessions</legend>
                <FormField
                    id="revoke_password"
                    v-model="password"
                    label="Current password"
                    type="password"
                    autocomplete="current-password"
                    :error="error"
                />
                <Button type="submit" class="wd-button wd-button-danger">{{
                    pending ? 'Signing out…' : 'Sign out all sessions'
                }}</Button>
            </fieldset>
        </form>
    </details>
</template>
