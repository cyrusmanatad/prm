<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { amountInput, formatMoney, parseAmount, smaller, subtract } from './amounts';
import type { OpenDraft, Payment } from './types';

/**
 * Allocate part of a receipt to an open draft in the same currency. The amount starts at the most it can be:
 * the smaller of the receipt's unallocated amount and the draft's balance.
 */
const props = defineProps<{ drafts: OpenDraft[]; errors: Record<string, string>; busy: boolean }>();
const emit = defineEmits<{ save: [payment: Payment, data: Record<string, unknown>] }>();
const dialog = ref<HTMLDialogElement>();
const payment = ref<Payment | null>(null);
const invoiceId = ref('');
const amount = ref('');
const local = ref<Record<string, string>>({});
let trigger: HTMLElement | null = null;

const unallocated = computed(() =>
    payment.value ? subtract(payment.value.amount_minor, payment.value.allocated_minor) : '0',
);
const choices = computed(() => props.drafts.filter((draft) => draft.currency === payment.value?.currency));
const draft = computed(() => choices.value.find((item) => item.id === invoiceId.value) ?? null);
const limit = computed(() =>
    draft.value ? smaller(unallocated.value, subtract(draft.value.total_minor, draft.value.received_minor)) : null,
);
const minor = computed(() => (payment.value ? parseAmount(amount.value, payment.value.currency) : null));
const money = (value: string) => formatMoney(value, payment.value?.currency ?? '');
const error = (key: string) => local.value[key] ?? props.errors[key];
const options = computed(() =>
    choices.value.map((item) => ({
        value: item.id,
        label: `${item.recipient_name} · balance ${money(subtract(item.total_minor, item.received_minor))}`,
    })),
);
watch(limit, (value) => {
    if (value && payment.value) amount.value = amountInput(value, payment.value.currency);
});

function open(source: HTMLElement | null, receipt: Payment): void {
    trigger = source;
    payment.value = receipt;
    invoiceId.value = choices.value.length === 1 ? choices.value[0]!.id : '';
    amount.value = '';
    local.value = {};
    dialog.value?.showModal();
    nextTick(() => dialog.value?.querySelector<HTMLElement>('#allocation-draft')?.focus());
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
function save(): void {
    if (!payment.value) return;
    const errors: Record<string, string> = {};
    if (!draft.value) errors.invoice_id = 'Choose a draft.';
    else if (!minor.value || minor.value === '0') errors.amount_minor = 'Enter a positive amount.';
    else if (BigInt(minor.value) > BigInt(limit.value!))
        errors.amount_minor = `Allocate at most ${money(limit.value!)}.`;
    local.value = errors;
    if (Object.keys(errors).length) {
        nextTick(() => dialog.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        return;
    }
    emit('save', payment.value, {
        version: payment.value.version,
        invoice_id: draft.value!.id,
        invoice_version: draft.value!.version,
        amount_minor: minor.value!,
    });
}
defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        aria-labelledby="allocation-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="allocation-title">Allocate receipt</h2>
                <p v-if="payment" class="rec-dialog-sub">
                    {{ payment.evidence_reference }} · {{ money(unallocated) }} unallocated
                </p>
            </div>
            <DialogClose label="Close allocate receipt" :disabled="busy" @click="close" />
        </header>
        <form class="rec-dialog-form" novalidate :aria-busy="busy" @submit.prevent="save">
            <div class="rec-dialog-body">
                <div v-if="errors.version" class="wd-banner rec-banner" role="alert">
                    <p>{{ errors.version }}</p>
                </div>
                <p v-if="!choices.length" class="rec-warning" role="status">
                    No open {{ payment?.currency }} draft has a balance to allocate to.
                </p>
                <fieldset class="rec-fields" :disabled="busy || !choices.length">
                    <legend class="wd-sr-only">Allocation</legend>
                    <SelectField
                        id="allocation-draft"
                        v-model="invoiceId"
                        label="Draft"
                        :options="options"
                        :error="error('invoice_id')"
                        :hint="`Only open ${payment?.currency ?? ''} drafts with a balance are listed.`"
                    />
                    <div class="wd-field">
                        <label for="allocation-amount">Amount ({{ payment?.currency }})</label>
                        <input
                            id="allocation-amount"
                            v-model="amount"
                            inputmode="decimal"
                            :aria-invalid="error('amount_minor') ? 'true' : undefined"
                            :aria-describedby="
                                error('amount_minor') ? 'allocation-amount-error' : 'allocation-amount-hint'
                            "
                        />
                        <p v-if="error('amount_minor')" id="allocation-amount-error" class="wd-field-error">
                            {{ error('amount_minor') }}
                        </p>
                        <p v-else id="allocation-amount-hint" class="wd-hint">
                            <template v-if="limit"
                                >Up to {{ money(limit) }}, the smaller of the receipt and draft balances.</template
                            >
                            <template v-if="minor"> Exact: {{ minor }} minor units.</template>
                        </p>
                    </div>
                </fieldset>
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="close"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="wd-button wd-button-primary"
                    :disabled="busy || !choices.length || !!errors.version"
                    >{{ busy ? 'Saving…' : 'Allocate' }}</Button
                >
            </footer>
        </form>
    </dialog>
</template>
