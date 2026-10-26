<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { useValidationFocus } from '../../composables/useValidationFocus';

export type Preferences = {
    timezone: string;
    reminders: boolean;
    weekly_digest: boolean;
    follow_up_drafts: boolean;
    version: number;
};
const props = defineProps<{ preferences: Preferences; timezones: string[]; canWrite: boolean }>();
const root = ref<HTMLElement>();
const form = useForm({ ...props.preferences });
const choices = [
    ['reminders', 'Reminder notices'],
    ['weekly_digest', 'Weekly digest'],
    ['follow_up_drafts', 'Follow-up draft review notices'],
] as const;
const withdrawing = computed(() => choices.some(([key]) => props.preferences[key] && !form[key]));
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function save(event: Event): void {
    if (form.processing || form.errors.version) return;
    const submit = () =>
        form.put('/automation/preferences', {
            preserveScroll: true,
            onSuccess: () => {
                form.version = props.preferences.version;
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
    <ContentSection
        title="Your preferences"
        description="These choices apply only to you in this workspace. All optional notices start off."
    >
        <form ref="root" :aria-busy="form.processing" @submit.prevent="save">
            <div v-if="form.errors.version" class="wd-banner" role="alert">
                <p>{{ form.errors.version }}</p>
                <button type="button" class="wd-link" @click="router.get('/automation', {}, { preserveState: false })">
                    Reload preferences
                </button>
            </div>
            <fieldset class="wd-fields" :disabled="!canWrite || form.processing || !!form.errors.version">
                <legend class="wd-sr-only">Optional notice preferences</legend>
                <SelectField
                    id="automation-timezone"
                    v-model="form.timezone"
                    label="Timezone"
                    :options="timezones.map((value) => ({ value, label: value }))"
                    :error="form.errors.timezone"
                    hint="Weekly schedules follow this local timezone. Changing it recalculates future weekly times."
                />
                <label v-for="[key, label] in choices" :key="key" class="wd-checkbox"
                    ><input
                        v-model="form[key]"
                        type="checkbox"
                        :aria-describedby="form.errors[key] ? `${key}-error` : undefined"
                    />{{ label
                    }}<span v-if="form.errors[key]" :id="`${key}-error`" class="wd-field-error" role="alert">{{
                        form.errors[key]
                    }}</span></label
                >
                <p class="wd-hint">
                    Turning a notice off withdraws consent and disables its schedules. Turning it on again requires
                    saving each schedule with fresh consent. Verification, password resets and explicitly requested
                    invitation emails keep their existing behavior.
                </p>
                <Button v-if="canWrite" class="wd-button wd-button-primary" type="submit">{{
                    form.processing ? 'Saving…' : 'Save preferences'
                }}</Button>
            </fieldset>
        </form>
    </ContentSection>
</template>
