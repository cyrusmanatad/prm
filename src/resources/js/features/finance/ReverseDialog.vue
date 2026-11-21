<script setup lang="ts">
import { nextTick, ref } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { formatMoney } from './amounts';
import type { Allocation, Payment } from './types';

/**
 * Collects the required reason for reversing an allocation. Continue closes this dialog and asks for the
 * shared Cancel-first confirmation, which names the allocation, its amount and the reason.
 */
defineProps<{ busy: boolean }>();
const emit = defineEmits<{
    confirm: [payment: Payment, allocation: Allocation, reason: string, trigger: HTMLElement | null];
}>();
const dialog = ref<HTMLDialogElement>();
const target = ref<{ payment: Payment; allocation: Allocation } | null>(null);
const reason = ref('');
const error = ref('');
let trigger: HTMLElement | null = null;
let continuing = false;

function open(source: HTMLElement | null, payment: Payment, allocation: Allocation): void {
    trigger = source;
    target.value = { payment, allocation };
    reason.value = '';
    error.value = '';
    dialog.value?.showModal();
    nextTick(() => dialog.value?.querySelector<HTMLElement>('#reverse-reason')?.focus());
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    if (!continuing) trigger?.focus();
    continuing = false;
}
function next(): void {
    if (!reason.value.trim()) {
        error.value = 'Enter why this allocation is being reversed.';
        nextTick(() => dialog.value?.querySelector<HTMLElement>('#reverse-reason')?.focus());
        return;
    }
    continuing = true;
    close();
    emit('confirm', target.value!.payment, target.value!.allocation, reason.value.trim(), trigger);
}
defineExpose({ open });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        aria-labelledby="reverse-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="reverse-title">Reverse allocation</h2>
                <p v-if="target" class="rec-dialog-sub">
                    {{ formatMoney(target.allocation.amount_minor, target.payment.currency) }} from
                    {{ target.payment.evidence_reference }} to {{ target.allocation.recipient_name ?? 'a draft' }}. The
                    history is kept.
                </p>
            </div>
            <DialogClose label="Close reverse allocation" :disabled="busy" @click="close" />
        </header>
        <form class="rec-dialog-form" novalidate @submit.prevent="next">
            <div class="rec-dialog-body">
                <div class="wd-field">
                    <label for="reverse-reason">Reason for reversing an allocation</label>
                    <input
                        id="reverse-reason"
                        v-model="reason"
                        maxlength="240"
                        placeholder="e.g. Applied to the wrong draft"
                        :aria-invalid="error ? 'true' : undefined"
                        :aria-describedby="error ? 'reverse-reason-error' : undefined"
                    />
                    <p v-if="error" id="reverse-reason-error" class="wd-field-error">{{ error }}</p>
                </div>
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" @click="close">Cancel</Button>
                <Button type="submit" class="wd-button wd-button-primary" :disabled="busy">Continue</Button>
            </footer>
        </form>
    </dialog>
</template>
