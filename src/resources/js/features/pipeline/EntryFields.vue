<script setup lang="ts">
import FormField from '../../components/FormField.vue';
import type { LeadForm } from './types';
const form = defineModel<LeadForm>({ required: true });
defineProps<{ prefix: string; errors: Record<string, string> }>();
</script>

<template>
    <FormField :id="`${prefix}-brand`" v-model="form.brand_name" label="Brand name" :maxlength="160" :error="errors.brand_name" />
    <FormField :id="`${prefix}-contact`" v-model="form.contact_name" :required="false" label="Contact name (optional)" :maxlength="120" :error="errors.contact_name" />
    <FormField :id="`${prefix}-email`" v-model="form.contact_email" :required="false" label="Contact email (optional)" type="email" :maxlength="255" :error="errors.contact_email" />
    <FormField :id="`${prefix}-currency`" v-model="form.currency" label="Currency code" :maxlength="3" hint="For example PHP, USD, or JPY. Totals stay separate for each currency." :error="errors.currency" />
    <FormField :id="`${prefix}-amount`" v-model="form.amount" label="Estimated value" inputmode="decimal" :maxlength="24" :error="errors.value_minor" />
    <FormField :id="`${prefix}-due`" v-model="form.next_action_due" :required="false" label="Next action (optional, your local time)" type="datetime-local" :error="errors.next_action_due" />
    <div class="wd-field">
        <label :for="`${prefix}-notes`">Private notes (optional)</label>
        <textarea :id="`${prefix}-notes`" v-model="form.notes" rows="3" maxlength="5000" :aria-invalid="!!errors.notes" :aria-describedby="errors.notes ? `${prefix}-notes-error` : undefined" />
        <p v-if="errors.notes" :id="`${prefix}-notes-error`" class="wd-field-error" role="alert">{{ errors.notes }}</p>
    </div>
</template>
