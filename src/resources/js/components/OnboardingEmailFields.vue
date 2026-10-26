<script setup lang="ts">
import FormField from './FormField.vue';
withDefaults(defineProps<{ prefix: string; errors?: Record<string, string>; purpose?: 'invitation' | 'outcome' }>(), {
    purpose: 'invitation',
});
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
            {{ purpose === 'outcome' ? 'Email this review outcome' : 'Email this onboarding invitation' }}
        </label>
        <p :id="`${prefix}-email-hint`" class="wd-hint">
            <template v-if="purpose === 'outcome'"
                >Optional. Send a fresh private return link with this outcome. Choose the intended recipient explicitly;
                the submitted business email is not selected for you. The link ends when the invitation
                expires.</template
            >
            <template v-else
                >Optional. Anyone with this private link can open the form once. Choose the intended recipient
                carefully.</template
            >
        </p>
        <p v-if="errors?.send_email" class="wd-field-error" role="alert">{{ errors.send_email }}</p>
        <FormField
            v-if="sendEmail"
            :id="`${prefix}-recipient-email`"
            v-model="recipient"
            :label="purpose === 'outcome' ? 'Outcome recipient email' : 'Invitation recipient email'"
            type="email"
            autocomplete="email"
            :maxlength="255"
            :error="errors?.recipient_email"
        />
    </div>
</template>
