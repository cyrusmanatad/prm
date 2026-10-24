<script setup lang="ts">
import FormField from './FormField.vue';
defineProps<{ prefix: string; errors?: Record<string, string> }>();
const sendEmail = defineModel<boolean>('sendEmail', { default: false });
const recipient = defineModel<string>('recipient', { default: '' });
</script>

<template>
    <div class="wd-fields">
        <label :for="`${prefix}-send-email`" class="flex items-start gap-2">
            <input
                :id="`${prefix}-send-email`"
                v-model="sendEmail"
                type="checkbox"
                class="shrink-0 accent-[var(--wd-brand)]"
                :aria-describedby="`${prefix}-email-hint`"
            />
            Email this onboarding invitation
        </label>
        <p :id="`${prefix}-email-hint`" class="wd-hint">
            Optional. Anyone with this private link can open the form once. Choose the intended recipient carefully.
        </p>
        <p v-if="errors?.send_email" class="wd-field-error" role="alert">{{ errors.send_email }}</p>
        <FormField
            v-if="sendEmail"
            :id="`${prefix}-recipient-email`"
            v-model="recipient"
            label="Invitation recipient email"
            type="email"
            autocomplete="email"
            :maxlength="255"
            :error="errors?.recipient_email"
        />
    </div>
</template>
