<script setup lang="ts">
import { nextTick, ref } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import type { FieldErrors } from '../../types/foundation';

/**
 * A compact console form in a modal dialog: the fields go in the default slot and server errors stay beside them.
 * Opening focuses the first field; closing returns focus to the control that opened it. It cannot be closed while
 * the form is submitting, and a failed save moves focus to the first field with an error. The page closes it after a
 * successful save, or a successful save navigates away.
 */
const props = defineProps<{
    id: string;
    title: string;
    description?: string;
    submitLabel: string;
    processing?: boolean;
    errors?: FieldErrors;
}>();
const emit = defineEmits<{ submit: [event: SubmitEvent] }>();
const dialog = ref<HTMLDialogElement>();
const form = ref<HTMLFormElement>();
let trigger: HTMLElement | null = null;
useValidationFocus(
    form,
    () => props.errors ?? {},
    () => !props.processing,
);

function open(source?: HTMLElement | null): void {
    trigger = source ?? (document.activeElement as HTMLElement | null);
    if (!dialog.value?.open) dialog.value?.showModal();
    nextTick(() => form.value?.querySelector<HTMLElement>('input, select, textarea, button[role="combobox"]')?.focus());
}
/** Cancel and the close button wait for a save to finish. */
function cancel(): void {
    if (!props.processing) dialog.value?.close();
}
/** For the page after a successful save, while the form still reports that it is processing. */
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        :aria-labelledby="`${id}-title`"
        @close="closed"
        @cancel="processing && $event.preventDefault()"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 :id="`${id}-title`">{{ title }}</h2>
                <p v-if="description" class="rec-dialog-sub">{{ description }}</p>
            </div>
            <DialogClose :label="`Close ${title.toLowerCase()}`" :disabled="processing" @click="cancel" />
        </header>
        <form ref="form" class="rec-dialog-form" novalidate @submit.prevent="emit('submit', $event as SubmitEvent)">
            <div class="rec-dialog-body">
                <slot />
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="processing" @click="cancel"
                    >Cancel</Button
                >
                <Button type="submit" class="wd-button wd-button-primary" :disabled="processing">{{
                    submitLabel
                }}</Button>
            </footer>
        </form>
    </dialog>
</template>
