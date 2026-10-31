<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import { Button } from '../../components/ui/button';
import { useValidationFocus } from '../../composables/useValidationFocus';
import type { FollowUpDraft, LeadOption } from './follow-up-types';

const props = defineProps<{ leads: LeadOption[]; selected: FollowUpDraft | null; canWrite: boolean }>();
const emit = defineEmits<{ close: [] }>();
const root = ref<HTMLElement>();
const form = useForm({
    pipeline_entry_id: props.selected?.pipeline_entry_id ?? '',
    recipient: props.selected?.recipient ?? '',
    subject: props.selected?.subject ?? '',
    body: props.selected?.body ?? '',
    version: props.selected?.version ?? 1,
});
const options = computed(() => props.leads.map((lead) => ({ value: lead.id, label: lead.brand_name })));
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function save(): void {
    if (form.processing || form.errors.version) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    };
    if (props.selected) {
        form.transform(({ pipeline_entry_id: _entry, ...data }) => data).patch(
            `/automation/drafts/${props.selected.id}`,
            options,
        );
    } else form.post('/automation/drafts', options);
}
</script>

<template>
    <ContentSection
        :title="selected ? 'Edit follow-up draft' : 'Write a follow-up'"
        description="Private to you. Review the recipient and message before approval. Saving an edit removes approval."
    >
        <p v-if="!leads.length && !selected" class="wd-empty">Add an active lead before writing a follow-up.</p>
        <form v-else-if="canWrite" ref="root" :aria-busy="form.processing" @submit.prevent="save">
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">Follow-up message</legend>
                <SelectField
                    v-if="!selected"
                    id="draft-lead"
                    v-model="form.pipeline_entry_id"
                    label="Lead"
                    :options="options"
                    :error="form.errors.pipeline_entry_id"
                />
                <FormField
                    id="draft-recipient"
                    v-model="form.recipient"
                    label="Recipient email"
                    type="email"
                    :maxlength="255"
                    :error="form.errors.recipient"
                    hint="One explicitly entered email address. No contacts or private notes are inserted automatically."
                />
                <FormField
                    id="draft-subject"
                    v-model="form.subject"
                    label="Message subject"
                    :maxlength="240"
                    :error="form.errors.subject"
                />
                <div class="wd-field">
                    <label for="draft-body">Message</label>
                    <textarea
                        id="draft-body"
                        v-model="form.body"
                        required
                        rows="6"
                        maxlength="10000"
                        :aria-invalid="!!form.errors.body"
                        :aria-describedby="form.errors.body ? 'draft-body-error' : undefined"
                    />
                    <p v-if="form.errors.body" id="draft-body-error" class="wd-field-error" role="alert">
                        {{ form.errors.body }}
                    </p>
                </div>
                <p v-if="form.errors.version" class="wd-field-error" role="alert">
                    {{ form.errors.version }} Reload this page to recover.
                </p>
                <ActionGroup>
                    <Button class="wd-button wd-button-primary" type="submit" :disabled="!!form.errors.version">{{
                        form.processing ? 'Saving…' : 'Save draft'
                    }}</Button>
                    <Button v-if="selected" class="wd-button wd-button-secondary" type="button" @click="emit('close')"
                        >Cancel editing</Button
                    >
                </ActionGroup>
            </fieldset>
        </form>
    </ContentSection>
</template>
