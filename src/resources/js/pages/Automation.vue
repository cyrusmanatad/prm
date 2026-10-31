<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import SelectField from '../components/SelectField.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';
import AutomationPreferences, { type Preferences } from '../features/automation/AutomationPreferences.vue';
import { confirmAction } from '../composables/useConfirmation';
import { useValidationFocus } from '../composables/useValidationFocus';

import ReminderSchedule, { type ReminderOption } from '../features/automation/ReminderSchedule.vue';
import NoticeHistory, { type Notice } from '../features/automation/NoticeHistory.vue';
import SavedSchedules from '../features/automation/SavedSchedules.vue';
import ApprovalInbox from '../features/automation/ApprovalInbox.vue';
import type { FollowUpDraft, LeadOption } from '../features/automation/follow-up-types';
import type { Schedule } from '../features/automation/types';
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    preferences: Preferences;
    schedules: Schedule[];
    reminders: ReminderOption[];
    drafts: FollowUpDraft[];
    leads: LeadOption[];
    notices: Notice[];
    timezones: string[];
    canWrite: boolean;
}>();
const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, index) => ({
    value: String(index + 1),
    label,
}));
const root = ref<HTMLElement>();
const selected = ref<string | null>(null);
const form = useForm({
    name: 'Weekly review',
    kind: 'weekly_digest',
    weekday: '1',
    local_time: '09:00',
    consent: false,
    version: 1,
});
const generalErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => ['kind', 'schedule'].includes(key))
        .map(([, value]) => value),
);
const revoke = useForm({ version: 1 });
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function choose(s?: Schedule): void {
    selected.value = s?.id ?? null;
    form.name = s?.name ?? 'Weekly review';
    form.weekday = String(s?.weekday ?? 1);
    form.local_time = s?.local_time ?? '09:00';
    form.version = s?.version ?? 1;
    form.consent = false;
    form.clearErrors();
}
function save(): void {
    if (form.processing || form.errors.version) return;
    const options = { preserveScroll: true, onSuccess: () => choose() };
    if (selected.value) form.patch(`/automation/schedules/${selected.value}`, options);
    else form.post('/automation/schedules', options);
}
function withdraw(s: Schedule, event: Event): void {
    if (revoke.processing) return;
    confirmAction(
        'Withdraw schedule consent',
        `Disable ${s.name} and cancel its pending scheduled work?`,
        () => {
            revoke.version = s.version;
            revoke.delete(`/automation/schedules/${s.id}`, { preserveScroll: true, onSuccess: () => choose() });
        },
        event,
    );
}
</script>

<template>
    <Head title="Automation preferences" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-workspace-content wd-section-stack">
            <nav aria-label="Workspace"><Link class="wd-link" href="/workspaces">Back to workspaces</Link></nav>
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Automation preferences</h1>
                <p class="wd-description">Choose your timezone and consent for optional scheduled notices.</p>
            </div>
            <p class="wd-banner">
                Reminder and weekly review emails go only to your verified account. Follow-up schedules prepare private
                drafts for your review; approval does not send email.
            </p>
            <p v-if="!canWrite" class="wd-hint">Viewer access: scheduling requires an editing role.</p>
            <AutomationPreferences
                :key="workspace.id"
                :preferences="preferences"
                :timezones="timezones"
                :can-write="canWrite"
            />
            <ContentSection
                title="Weekly schedule"
                description="One weekly digest schedule per person in this workspace. The digest will be addressed only to your verified account."
            >
                <p class="wd-hint">
                    During daylight-saving changes, a missing local time skips that week. A repeated local time runs at
                    its first occurrence. Missed weeks produce one pending review, with no backlog burst.
                </p>
                <form v-if="canWrite" :aria-busy="form.processing" @submit.prevent="save">
                    <div v-if="form.errors.version" class="wd-banner" role="alert">
                        <p>{{ form.errors.version }}</p>
                        <button
                            type="button"
                            class="wd-link"
                            @click="router.get('/automation', {}, { preserveState: false })"
                        >
                            Reload schedules
                        </button>
                    </div>
                    <fieldset
                        class="wd-fields"
                        :disabled="form.processing || revoke.processing || !!form.errors.version"
                    >
                        <legend>{{ selected ? 'Edit weekly schedule' : 'New weekly schedule' }}</legend>
                        <FormField
                            id="schedule-name"
                            v-model="form.name"
                            label="Schedule name"
                            :maxlength="160"
                            :error="form.errors.name"
                        />
                        <SelectField
                            id="schedule-weekday"
                            v-model="form.weekday"
                            label="Day of week"
                            :searchable="false"
                            :options="weekdays"
                            :error="form.errors.weekday"
                        />
                        <FormField
                            id="schedule-time"
                            v-model="form.local_time"
                            label="Local time (HH:MM)"
                            pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
                            :error="form.errors.local_time"
                            :hint="`Uses your saved timezone: ${preferences.timezone}`"
                        />
                        <label class="wd-checkbox"
                            ><input
                                v-model="form.consent"
                                type="checkbox"
                                required
                                :aria-invalid="form.errors.consent ? true : undefined"
                                :aria-describedby="form.errors.consent ? 'schedule-consent-error' : undefined"
                            />I consent to this weekly schedule for my account in this workspace.</label
                        >
                        <p v-if="form.errors.consent" id="schedule-consent-error" class="wd-field-error" role="alert">
                            {{ form.errors.consent }}
                        </p>
                        <p v-for="error in generalErrors" :key="error" class="wd-field-error" role="alert">
                            {{ error }}
                        </p>
                        <ActionGroup
                            ><Button class="wd-button wd-button-primary" type="submit">{{
                                form.processing ? 'Saving…' : 'Save weekly schedule'
                            }}</Button
                            ><Button
                                v-if="selected"
                                class="wd-button wd-button-secondary"
                                type="button"
                                @click="choose()"
                                >Cancel editing</Button
                            ></ActionGroup
                        >
                    </fieldset>
                </form>
            </ContentSection>
            <ReminderSchedule :reminders="reminders" :can-write="canWrite" />
            <ReminderSchedule :reminders="[]" :leads="leads" follow-up :can-write="canWrite" />
            <ApprovalInbox :drafts="drafts" :leads="leads" :can-write="canWrite" />
            <SavedSchedules
                :schedules="schedules"
                :can-write="canWrite"
                :busy="form.processing || revoke.processing"
                :error="revoke.errors.version"
                @edit="choose"
                @withdraw="withdraw"
            />
            <NoticeHistory :notices="notices" />
        </div>
    </FoundationShell>
</template>
