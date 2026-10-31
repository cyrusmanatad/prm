<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { useValidationFocus } from '../../composables/useValidationFocus';

export type ReminderOption = { id: string; title: string; due_at: string };
const props = defineProps<{ reminders: ReminderOption[]; canWrite: boolean }>();
const root = ref<HTMLElement>();
const form = useForm({ name: '', kind: 'reminder', subject_id: '', due_at: '', consent: false });
const generalErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => !['name', 'subject_id', 'due_at', 'consent'].includes(key))
        .map(([, value]) => value),
);
const options = computed(() => props.reminders.map((r) => ({ value: r.id, label: r.title })));
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
        title="Reminder email"
        description="Enable Reminder notices in your preferences, then consent to a one-time email for an open reminder."
    >
        <p class="wd-hint">
            Choose from the first 100 open reminders ordered by due date. Editing, snoozing, completing or deleting a
            reminder cancels its old scheduled email. Save a new schedule after a change.
        </p>
        <p v-if="!reminders.length" class="wd-empty">
            Create a reminder in Contacts and reminders before scheduling an email.
        </p>
        <form v-else-if="canWrite" ref="root" :aria-busy="form.processing" @submit.prevent="save">
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">New reminder email schedule</legend>
                <FormField
                    id="reminder-schedule-name"
                    v-model="form.name"
                    label="Reminder schedule name"
                    :maxlength="160"
                    :error="form.errors.name"
                />
                <SelectField
                    id="reminder-schedule-subject"
                    v-model="form.subject_id"
                    label="Reminder"
                    :options="options"
                    :error="form.errors.subject_id"
                />
                <FormField
                    id="reminder-schedule-due"
                    v-model="form.due_at"
                    type="datetime-local"
                    label="Email at"
                    :error="form.errors.due_at"
                    hint="Uses this browser’s local timezone. Choose a future time; the saved instant stays fixed if your preferences change."
                />
                <label class="wd-checkbox"
                    ><input
                        v-model="form.consent"
                        type="checkbox"
                        required
                        :aria-invalid="form.errors.consent ? true : undefined"
                        :aria-describedby="form.errors.consent ? 'reminder-schedule-consent-error' : undefined"
                    />I consent to this reminder email for my account in this workspace.</label
                >
                <p v-if="form.errors.consent" id="reminder-schedule-consent-error" class="wd-field-error" role="alert">
                    {{ form.errors.consent }}
                </p>
                <p v-for="error in generalErrors" :key="error" class="wd-field-error" role="alert">{{ error }}</p>
                <Button class="wd-button wd-button-primary" type="submit">{{
                    form.processing ? 'Saving…' : 'Schedule reminder email'
                }}</Button>
            </fieldset>
        </form>
    </ContentSection>
</template>
