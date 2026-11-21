<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import type { Preferences } from './AutomationPreferences.vue';
import type { LeadOption } from './follow-up-types';
import { scheduleKinds, weekdays, type Schedule, type ScheduleKind } from './types';

export type ReminderOption = { id: string; title: string; due_at: string };
/**
 * New schedule (any kind) or Edit weekly digest, in one dialog. A kind switched off in preferences explains
 * why it cannot be saved and offers the Preferences tab instead of sending a request that would be refused.
 */
const props = defineProps<{
    preferences: Preferences;
    schedules: Schedule[];
    reminders: ReminderOption[];
    leads: LeadOption[];
}>();
const emit = defineEmits<{ preferences: [] }>();
const dialog = ref<HTMLDialogElement>();
const body = ref<HTMLElement>();
const editing = ref<Schedule | null>(null);
let trigger: HTMLElement | null = null;
const form = useForm({
    name: '',
    kind: 'weekly_digest' as ScheduleKind,
    weekday: '1',
    local_time: '09:00',
    subject_id: '',
    due_at: '',
    consent: false,
    version: 1,
});
useValidationFocus(
    body,
    () => form.errors,
    () => !form.processing,
);
const kind = computed(() => scheduleKinds.find((item) => item.value === form.kind)!);
const enabled = computed(() => !!props.preferences[kind.value.preference]);
const activeWeekly = computed(() =>
    props.schedules.find(
        (schedule) => schedule.kind === 'weekly_digest' && schedule.enabled && schedule.id !== editing.value?.id,
    ),
);
const subjects = computed(() =>
    form.kind === 'reminder'
        ? props.reminders.map((reminder) => ({ value: reminder.id, label: reminder.title }))
        : props.leads.map((lead) => ({ value: lead.id, label: lead.brand_name })),
);
const blocked = computed(
    () =>
        !enabled.value ||
        (form.kind === 'weekly_digest' && !!activeWeekly.value) ||
        (form.kind !== 'weekly_digest' && !subjects.value.length),
);
const generalErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => ['kind', 'schedule', 'version'].includes(key))
        .map(([, value]) => value),
);
const weekdayOptions = weekdays.map((label, index) => ({ value: String(index + 1), label }));
const consentLabel = computed(() =>
    form.kind === 'weekly_digest'
        ? 'I consent to this weekly schedule for my account in this workspace.'
        : form.kind === 'reminder'
          ? 'I consent to this reminder email for my account in this workspace.'
          : 'I consent to private follow-up draft preparation in this workspace.',
);

async function open(source: HTMLElement | null, schedule?: Schedule): Promise<void> {
    trigger = source;
    editing.value = schedule ?? null;
    form.reset();
    form.clearErrors();
    if (schedule) {
        form.kind = 'weekly_digest';
        form.name = schedule.name;
        form.weekday = String(schedule.weekday ?? 1);
        form.local_time = schedule.local_time ?? '09:00';
        form.version = schedule.version;
    }
    dialog.value?.showModal();
    await nextTick();
    dialog.value
        ?.querySelector<HTMLElement>(schedule ? '#schedule-name' : '[role="radio"][aria-checked="true"]')
        ?.focus();
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
function choose(value: ScheduleKind): void {
    form.kind = value;
    form.subject_id = '';
    form.clearErrors();
}
function moveKind(event: KeyboardEvent, index: number): void {
    const step =
        event.key === 'ArrowRight' || event.key === 'ArrowDown'
            ? 1
            : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
              ? -1
              : 0;
    if (!step) return;
    event.preventDefault();
    const next = scheduleKinds[(index + step + scheduleKinds.length) % scheduleKinds.length]!;
    choose(next.value);
    nextTick(() => dialog.value?.querySelector<HTMLElement>(`#schedule-kind-${next.value}`)?.focus());
}
function openPreferences(): void {
    // Focus moves to the Preferences tab, not back to New schedule.
    trigger = null;
    close();
    emit('preferences');
}
function save(): void {
    if (form.processing || blocked.value) return;
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => close() };
    if (form.kind === 'weekly_digest') {
        form.transform((data) => ({
            name: data.name,
            kind: data.kind,
            weekday: data.weekday,
            local_time: data.local_time,
            consent: data.consent,
            ...(editing.value ? { version: data.version } : {}),
        }));
        if (editing.value) form.patch(`/automation/schedules/${editing.value.id}`, options);
        else form.post('/automation/schedules', options);
        return;
    }
    const instant = new Date(form.due_at);
    if (!form.due_at || Number.isNaN(instant.getTime())) {
        form.setError('due_at', 'Choose a future date and time.');
        return;
    }
    form.transform((data) => ({
        name: data.name,
        kind: data.kind,
        subject_id: data.subject_id,
        due_at: instant.toISOString().replace(/\.\d{3}Z$/, '+00:00'),
        consent: data.consent,
    })).post('/automation/schedules', options);
}
defineExpose({ open });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog auto-dialog"
        aria-labelledby="schedule-dialog-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="auto-dialog-head">
            <div>
                <h2 id="schedule-dialog-title">{{ editing ? 'Edit weekly digest' : 'New schedule' }}</h2>
                <p class="auto-dialog-sub">
                    Each schedule needs your consent.
                    {{
                        form.kind === 'weekly_digest'
                            ? `Times use your saved timezone (${preferences.timezone}).`
                            : 'Times use this browser’s timezone and stay fixed if your preferences change.'
                    }}
                </p>
            </div>
            <DialogClose label="Close schedule" :disabled="form.processing" @click="close" />
        </header>
        <form class="auto-dialog-form" novalidate :aria-busy="form.processing" @submit.prevent="save">
            <div ref="body" class="auto-dialog-body">
                <div v-if="!editing" class="auto-segments" role="radiogroup" aria-label="Schedule type">
                    <button
                        v-for="(item, index) in scheduleKinds"
                        :id="`schedule-kind-${item.value}`"
                        :key="item.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.kind === item.value"
                        :tabindex="form.kind === item.value ? 0 : -1"
                        @click="choose(item.value)"
                        @keydown="moveKind($event, index)"
                    >
                        {{ item.label }}
                    </button>
                </div>
                <div v-if="!enabled" class="auto-warning" role="status">
                    <p>{{ kind.label }} notices are off in your preferences. Turn them on before scheduling.</p>
                    <button type="button" class="wd-link" @click="openPreferences">Open preferences</button>
                </div>
                <p v-else-if="form.kind === 'weekly_digest' && activeWeekly" class="auto-warning" role="status">
                    You already have an active weekly digest, “{{ activeWeekly.name }}”. Edit it from the Schedules tab.
                </p>
                <p v-else-if="form.kind !== 'weekly_digest' && !subjects.length" class="auto-warning" role="status">
                    {{
                        form.kind === 'reminder'
                            ? 'Create an open reminder in Contacts and reminders first.'
                            : 'Add an active lead first.'
                    }}
                </p>
                <div v-for="error in generalErrors" :key="error" class="wd-banner auto-banner" role="alert">
                    <p>{{ error }}</p>
                </div>
                <fieldset class="auto-fields" :disabled="form.processing || blocked">
                    <legend class="wd-sr-only">{{ kind.label }} schedule</legend>
                    <FormField
                        id="schedule-name"
                        v-model="form.name"
                        label="Schedule name"
                        :maxlength="160"
                        :error="form.errors.name"
                    />
                    <div v-if="form.kind === 'weekly_digest'" class="auto-field-row">
                        <SelectField
                            id="schedule-weekday"
                            v-model="form.weekday"
                            label="Day of week"
                            :searchable="false"
                            :options="weekdayOptions"
                            :error="form.errors.weekday"
                        />
                        <FormField
                            id="schedule-time"
                            v-model="form.local_time"
                            label="Local time (HH:MM)"
                            pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
                            :error="form.errors.local_time"
                        />
                    </div>
                    <template v-else>
                        <SelectField
                            id="schedule-subject"
                            v-model="form.subject_id"
                            :label="form.kind === 'reminder' ? 'Reminder' : 'Follow-up lead'"
                            :options="subjects"
                            :error="form.errors.subject_id"
                            :hint="
                                form.kind === 'reminder'
                                    ? 'The first 100 open reminders by due date. Changing the reminder cancels this email.'
                                    : 'The newest 100 active leads. Changing the lead cancels its prepared draft.'
                            "
                        />
                        <FormField
                            id="schedule-due"
                            v-model="form.due_at"
                            type="datetime-local"
                            :label="form.kind === 'reminder' ? 'Email at' : 'Prepare draft at'"
                            :error="form.errors.due_at"
                        />
                    </template>
                    <div class="wd-field">
                        <label class="wd-checkbox"
                            ><input
                                v-model="form.consent"
                                type="checkbox"
                                :aria-invalid="form.errors.consent ? true : undefined"
                                :aria-describedby="form.errors.consent ? 'schedule-consent-error' : undefined"
                            />{{ consentLabel }}</label
                        >
                        <p v-if="form.errors.consent" id="schedule-consent-error" class="wd-field-error" role="alert">
                            {{ form.errors.consent }}
                        </p>
                    </div>
                </fieldset>
            </div>
            <footer class="auto-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="form.processing" @click="close"
                    >Cancel</Button
                >
                <Button type="submit" class="wd-button wd-button-primary" :disabled="form.processing || blocked">{{
                    form.processing ? 'Saving…' : 'Save schedule'
                }}</Button>
            </footer>
        </form>
    </dialog>
</template>
