<script setup lang="ts">
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import type { LeadOption } from './follow-up-types';

export type FollowUpValues = { pipeline_entry_id: string; recipient: string; subject: string; body: string };
/**
 * The follow-up message fields, shared by Write follow-up and editing in the draft drawer. `values` is the
 * caller's form, whose fields these inputs edit in place.
 */
defineProps<{
    values: FollowUpValues;
    prefix: string;
    errors: Partial<Record<string, string>>;
    leads?: LeadOption[];
}>();
</script>

<template>
    <SelectField
        v-if="leads"
        :id="`${prefix}-lead`"
        v-model="values.pipeline_entry_id"
        label="Lead"
        :options="leads.map((lead) => ({ value: lead.id, label: lead.brand_name }))"
        :error="errors.pipeline_entry_id"
    />
    <FormField
        :id="`${prefix}-recipient`"
        v-model="values.recipient"
        label="Recipient email"
        type="email"
        autocomplete="off"
        :maxlength="255"
        :error="errors.recipient"
        hint="One address you enter. Contacts and private notes are never inserted automatically."
    />
    <FormField
        :id="`${prefix}-subject`"
        v-model="values.subject"
        label="Message subject"
        :maxlength="240"
        :error="errors.subject"
    />
    <div class="wd-field">
        <label :for="`${prefix}-body`">Message</label>
        <textarea
            :id="`${prefix}-body`"
            v-model="values.body"
            rows="7"
            maxlength="10000"
            :aria-invalid="errors.body ? 'true' : undefined"
            :aria-describedby="errors.body ? `${prefix}-body-error` : undefined"
        />
        <p v-if="errors.body" :id="`${prefix}-body-error`" class="wd-field-error" role="alert">{{ errors.body }}</p>
    </div>
</template>
