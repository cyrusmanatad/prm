<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import type { LeadOption } from './follow-up-types';
import { useValidationFocus } from '../../composables/useValidationFocus';

export type ReminderOption = { id: string; title: string; due_at: string };
const props = defineProps<{
    reminders: ReminderOption[];
    canWrite: boolean;
    leads?: LeadOption[];
    followUp?: boolean;
}>();
const root = ref<HTMLElement>();
const form = useForm({
    name: '',
    kind: props.followUp ? 'follow_up_draft' : 'reminder',
    subject_id: '',
    due_at: '',
    consent: false,
});
const generalErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => !['name', 'subject_id', 'due_at', 'consent'].includes(key))
        .map(([, value]) => value),
);
const options = computed(() =>
    props.followUp
        ? (props.leads ?? []).map((r) => ({ value: r.id, label: r.brand_name }))
        : props.reminders.map((r) => ({ value: r.id, label: r.title })),
);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function save(): void {
    if (form.processing || !form.due_at) return;
    const instant = new Date(form.due_at);
    if (Number.isNaN(instant.getTime())) return;
    form.transform((data) => ({ ...data, due_at: instant.toISOString().replace('.000Z', '+00:00') })).post(
        '/automation/schedules',
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <ContentSection
        :title="followUp ? 'Schedule a private follow-up draft' : 'Reminder email'"
        :description="
            followUp
                ? 'Enable Follow-up drafts in preferences, then consent to one-time preparation. Nothing is sent.'
                : 'Enable Reminder notices in your preferences, then consent to a one-time email for an open reminder.'
        "
    >
        <p v-if="followUp" class="wd-hint">
            Choose from the newest 100 active leads. Changes to the lead or consent prevent use of its old scheduled
            draft. Create a fresh manual draft after a change.
        </p>
        <p v-if="!followUp" class="wd-hint">
            Choose from the first 100 open reminders ordered by due date. Editing, snoozing, completing or deleting a
            reminder cancels its old scheduled email. Save a new schedule after a change.
        </p>
        <p v-if="!options.length" class="wd-empty">
            {{
                followUp
                    ? 'Add an active lead before scheduling a draft.'
                    : 'Create a reminder in Contacts and reminders before scheduling an email.'
            }}
        </p>
        <form v-else-if="canWrite" ref="root" :aria-busy="form.processing" @submit.prevent="save">
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">
                    {{ followUp ? 'New follow-up draft schedule' : 'New reminder email schedule' }}
                </legend>
                <FormField
                    :id="`${followUp ? 'follow-up' : 'reminder'}-schedule-name`"
                    v-model="form.name"
                    :label="followUp ? 'Follow-up schedule name' : 'Reminder schedule name'"
                    :maxlength="160"
                    :error="form.errors.name"
                />
                <SelectField
                    :id="`${followUp ? 'follow-up' : 'reminder'}-schedule-subject`"
                    v-model="form.subject_id"
                    :label="followUp ? 'Follow-up lead' : 'Reminder'"
                    :options="options"
                    :error="form.errors.subject_id"
                />
                <FormField
                    :id="`${followUp ? 'follow-up' : 'reminder'}-schedule-due`"
                    v-model="form.due_at"
                    type="datetime-local"
                    :label="followUp ? 'Prepare draft at' : 'Email at'"
                    :error="form.errors.due_at"
                    hint="Uses this browser’s local timezone. Choose a future time; the saved instant stays fixed if your preferences change."
                />
                <label class="wd-checkbox"
                    ><input
                        v-model="form.consent"
                        type="checkbox"
                        required
                        :aria-invalid="form.errors.consent ? true : undefined"
                        :aria-describedby="
                            form.errors.consent
                                ? `${followUp ? 'follow-up' : 'reminder'}-schedule-consent-error`
                                : undefined
                        "
                    />{{
                        followUp
                            ? 'I consent to private follow-up draft preparation in this workspace.'
                            : 'I consent to this reminder email for my account in this workspace.'
                    }}</label
                >
                <p
                    v-if="form.errors.consent"
                    :id="`${followUp ? 'follow-up' : 'reminder'}-schedule-consent-error`"
                    class="wd-field-error"
                    role="alert"
                >
                    {{ form.errors.consent }}
                </p>
                <p v-for="error in generalErrors" :key="error" class="wd-field-error" role="alert">{{ error }}</p>
                <Button class="wd-button wd-button-primary" type="submit">{{
                    form.processing ? 'Saving…' : followUp ? 'Schedule follow-up draft' : 'Schedule reminder email'
                }}</Button>
            </fieldset>
        </form>
    </ContentSection>
</template>
