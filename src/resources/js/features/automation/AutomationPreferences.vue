<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { useValidationFocus } from '../../composables/useValidationFocus';

export type Preferences = {
    timezone: string;
    reminders: boolean;
    weekly_digest: boolean;
    follow_up_drafts: boolean;
    follow_up_reviews?: boolean;
    version: number;
};
const props = defineProps<{ preferences: Preferences; timezones: string[]; canWrite: boolean; reload: string }>();
const root = ref<HTMLElement>();
const saved = () => ({ ...props.preferences, follow_up_reviews: props.preferences.follow_up_reviews ?? false });
const form = useForm(saved());
const choices = [
    ['reminders', 'Reminder notices', 'One-time emails for reminders you schedule.'],
    ['weekly_digest', 'Weekly digest', 'A weekly review email to your verified account.'],
    ['follow_up_drafts', 'Follow-up drafts', 'Schedules prepare private drafts for your review. Nothing is sent.'],
    ['follow_up_reviews', 'Follow-up review emails to my account', 'Email me when a new draft needs review.'],
] as const;
const timezoneOptions = computed(() => props.timezones.map((value) => ({ value, label: value })));
const dirty = computed(
    () => form.timezone !== props.preferences.timezone || choices.some(([key]) => form[key] !== saved()[key]),
);
const withdrawing = computed(() => choices.some(([key]) => saved()[key] && !form[key]));
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function discard(): void {
    const values = saved();
    form.timezone = values.timezone;
    for (const [key] of choices) form[key] = values[key];
    form.clearErrors();
}
function save(event: Event): void {
    if (form.processing || form.errors.version || !dirty.value) return;
    const submit = () =>
        form.put('/automation/preferences', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.version = props.preferences.version;
                form.defaults(saved());
            },
        });
    if (withdrawing.value) {
        confirmAction(
            'Withdraw notice consent',
            'Disable the selected notice types and cancel their pending scheduled work in this workspace?',
            submit,
            event,
        );
    } else submit();
}
</script>

<template>
    <section class="auto-card" aria-labelledby="automation-preferences-heading">
        <header class="auto-card-head">
            <h2 id="automation-preferences-heading">Your notice preferences</h2>
            <p class="auto-note">Only you, in this workspace. Optional notices start off.</p>
        </header>
        <form ref="root" novalidate :aria-busy="form.processing" @submit.prevent="save">
            <div v-if="form.errors.version" class="wd-banner auto-banner" role="alert">
                <p>{{ form.errors.version }}</p>
                <button type="button" class="wd-link" @click="router.get(reload, {}, { preserveState: false })">
                    Reload preferences
                </button>
            </div>
            <fieldset class="auto-prefs" :disabled="!canWrite || form.processing || !!form.errors.version">
                <legend class="wd-sr-only">Optional notice preferences</legend>
                <div class="auto-prefs-timezone">
                    <SelectField
                        id="automation-timezone"
                        v-model="form.timezone"
                        label="Timezone"
                        :options="timezoneOptions"
                        :error="form.errors.timezone"
                        hint="Weekly schedules follow this timezone. Changing it recalculates future weekly times."
                    />
                </div>
                <ul class="auto-switches">
                    <li v-for="[key, label, description] in choices" :key="key">
                        <div class="auto-switch-row">
                            <span class="auto-switch-text"
                                ><label :for="`automation-${key}`">{{ label }}</label
                                ><span :id="`automation-${key}-description`">{{ description }}</span></span
                            >
                            <input
                                :id="`automation-${key}`"
                                v-model="form[key]"
                                class="auto-switch"
                                type="checkbox"
                                :aria-describedby="
                                    [`automation-${key}-description`, form.errors[key] ? `${key}-error` : '']
                                        .filter(Boolean)
                                        .join(' ')
                                "
                            />
                        </div>
                        <p v-if="form.errors[key]" :id="`${key}-error`" class="wd-field-error" role="alert">
                            {{ form.errors[key] }}
                        </p>
                    </li>
                </ul>
                <p class="auto-fine">
                    Turning a notice off withdraws consent and disables its schedules; turn it on and save each schedule
                    again with fresh consent. Review emails apply only to new drafts. Verification, password resets and
                    invitation emails are not affected.
                </p>
            </fieldset>
            <footer v-if="canWrite" class="auto-sticky">
                <p v-if="dirty" class="auto-dirty" role="status"><span aria-hidden="true"></span>Unsaved changes</p>
                <p v-else class="auto-clean">No unsaved changes</p>
                <Button
                    v-if="dirty"
                    type="button"
                    class="wd-button wd-button-secondary"
                    :disabled="form.processing"
                    @click="discard"
                    >Discard</Button
                >
                <Button
                    class="wd-button wd-button-primary"
                    type="submit"
                    :disabled="form.processing || !dirty || !!form.errors.version"
                    >{{ form.processing ? 'Saving…' : 'Save preferences' }}</Button
                >
            </footer>
        </form>
    </section>
</template>
