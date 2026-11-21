<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FollowUpFields, { type FollowUpValues } from './FollowUpFields.vue';
import type { LeadOption } from './follow-up-types';

/** Write follow-up: a new private draft. The saved draft then opens in the drawer for review. */
defineProps<{ leads: LeadOption[] }>();
const dialog = ref<HTMLDialogElement>();
const body = ref<HTMLElement>();
let trigger: HTMLElement | null = null;
const form = useForm<FollowUpValues>({ pipeline_entry_id: '', recipient: '', subject: '', body: '' });
useValidationFocus(
    body,
    () => form.errors,
    () => !form.processing,
);
async function open(source: HTMLElement | null): Promise<void> {
    trigger = source;
    form.reset();
    form.clearErrors();
    dialog.value?.showModal();
    await nextTick();
    dialog.value?.querySelector<HTMLElement>('#new-draft-lead')?.focus();
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
function save(): void {
    if (form.processing) return;
    form.post('/automation/drafts', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // The new draft's drawer takes focus; the trigger is no longer where the user is working.
            trigger = null;
            close();
        },
    });
}
defineExpose({ open });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        aria-labelledby="new-draft-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="new-draft-title">Write a follow-up</h2>
                <p class="rec-dialog-sub">Private to you. Review the recipient and message before approval.</p>
            </div>
            <DialogClose label="Close write a follow-up" :disabled="form.processing" @click="close" />
        </header>
        <form class="rec-dialog-form" novalidate :aria-busy="form.processing" @submit.prevent="save">
            <div ref="body" class="rec-dialog-body">
                <p v-if="!leads.length" class="rec-warning" role="status">
                    Add an active lead before writing a follow-up.
                </p>
                <fieldset class="rec-fields" :disabled="form.processing || !leads.length">
                    <legend class="wd-sr-only">Follow-up message</legend>
                    <FollowUpFields :values="form" prefix="new-draft" :errors="form.errors" :leads="leads" />
                </fieldset>
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="form.processing" @click="close"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="wd-button wd-button-primary"
                    :disabled="form.processing || !leads.length"
                    >{{ form.processing ? 'Saving…' : 'Save draft' }}</Button
                >
            </footer>
        </form>
    </dialog>
</template>
