<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import DialogClose from '../../components/DialogClose.vue';
import SelectField from '../../components/SelectField.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { amountInput, formatMoney, parseAmount, today, validCurrency } from './amounts';
import type { Payment } from './types';

/** Record a receipt already received, optionally correcting a voided one. Amounts are typed in currency units. */
const props = defineProps<{ voided: Payment[]; errors: Record<string, string>; busy: boolean }>();
const emit = defineEmits<{ save: [data: Record<string, unknown>] }>();
const dialog = ref<HTMLDialogElement>();
const form = ref({ currency: '', amount: '', received_on: '', evidence_reference: '', correction_of: '' });
const local = ref<Record<string, string>>({});
let trigger: HTMLElement | null = null;
const currency = computed(() => form.value.currency.trim().toUpperCase());
const minor = computed(() => parseAmount(form.value.amount, currency.value));
const error = (key: string) => local.value[key] ?? props.errors[key];
const corrections = computed(() => [
    { value: '', label: 'No correction' },
    ...props.voided.map((p) => ({
        value: p.id,
        label: `${p.evidence_reference} · ${formatMoney(p.amount_minor, p.currency)}`,
    })),
]);
const general = computed(() =>
    Object.entries(props.errors)
        .filter(
            ([key]) =>
                !['currency', 'amount_minor', 'received_on', 'evidence_reference', 'correction_of'].includes(key),
        )
        .map(([, message]) => message),
);

function open(source: HTMLElement | null, correcting?: Payment): void {
    trigger = source;
    form.value = {
        currency: correcting?.currency ?? '',
        amount: correcting ? amountInput(correcting.amount_minor, correcting.currency) : '',
        received_on: correcting?.received_on ?? today(),
        evidence_reference: '',
        correction_of: correcting?.id ?? '',
    };
    local.value = {};
    dialog.value?.showModal();
    nextTick(() =>
        dialog.value?.querySelector<HTMLElement>(correcting ? '#receipt-reference' : '#receipt-currency')?.focus(),
    );
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
function save(): void {
    const errors: Record<string, string> = {};
    if (!validCurrency(currency.value)) errors.currency = 'Enter a three-letter currency code, such as USD.';
    else if (!minor.value || minor.value === '0')
        errors.amount_minor = `Enter a positive amount such as ${amountInput('90000', currency.value)}.`;
    if (!form.value.received_on) errors.received_on = 'Choose the date you received it.';
    if (!form.value.evidence_reference.trim()) errors.evidence_reference = 'Enter a unique reference.';
    local.value = errors;
    if (Object.keys(errors).length) {
        nextTick(() => dialog.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        return;
    }
    emit('save', {
        currency: currency.value,
        amount_minor: minor.value!,
        received_on: form.value.received_on,
        evidence_reference: form.value.evidence_reference.trim(),
        correction_of: form.value.correction_of || null,
    });
}
defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        aria-labelledby="receipt-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="receipt-title">Record receipt</h2>
                <p class="rec-dialog-sub">A receipt you already received. Nothing is charged or requested.</p>
            </div>
            <DialogClose label="Close record receipt" :disabled="busy" @click="close" />
        </header>
        <form class="rec-dialog-form" novalidate :aria-busy="busy" @submit.prevent="save">
            <div class="rec-dialog-body">
                <div v-if="general.length" class="wd-banner rec-banner" role="alert">
                    <p v-for="message in general" :key="message">{{ message }}</p>
                </div>
                <fieldset class="rec-fields" :disabled="busy">
                    <legend class="wd-sr-only">Receipt</legend>
                    <div class="rec-field-row">
                        <div class="wd-field">
                            <label for="receipt-currency">Currency code</label>
                            <input
                                id="receipt-currency"
                                v-model="form.currency"
                                maxlength="3"
                                placeholder="USD"
                                autocapitalize="characters"
                                :aria-invalid="error('currency') ? 'true' : undefined"
                                :aria-describedby="error('currency') ? 'receipt-currency-error' : undefined"
                            />
                            <p v-if="error('currency')" id="receipt-currency-error" class="wd-field-error">
                                {{ error('currency') }}
                            </p>
                        </div>
                        <div class="wd-field">
                            <label for="receipt-amount">Amount</label>
                            <input
                                id="receipt-amount"
                                v-model="form.amount"
                                inputmode="decimal"
                                placeholder="0.00"
                                :aria-invalid="error('amount_minor') ? 'true' : undefined"
                                :aria-describedby="
                                    error('amount_minor')
                                        ? 'receipt-amount-error'
                                        : minor
                                          ? 'receipt-amount-hint'
                                          : undefined
                                "
                            />
                            <p v-if="error('amount_minor')" id="receipt-amount-error" class="wd-field-error">
                                {{ error('amount_minor') }}
                            </p>
                            <p v-else-if="minor" id="receipt-amount-hint" class="wd-hint">
                                Exact: {{ minor }} minor units
                            </p>
                        </div>
                    </div>
                    <div class="rec-field-row">
                        <div class="wd-field">
                            <label for="receipt-date">Received on</label>
                            <input
                                id="receipt-date"
                                v-model="form.received_on"
                                type="date"
                                :aria-invalid="error('received_on') ? 'true' : undefined"
                                :aria-describedby="error('received_on') ? 'receipt-date-error' : undefined"
                            />
                            <p v-if="error('received_on')" id="receipt-date-error" class="wd-field-error">
                                {{ error('received_on') }}
                            </p>
                        </div>
                        <div class="wd-field">
                            <label for="receipt-reference">Evidence reference</label>
                            <input
                                id="receipt-reference"
                                v-model="form.evidence_reference"
                                maxlength="160"
                                placeholder="e.g. Bank transfer 4471"
                                :aria-invalid="error('evidence_reference') ? 'true' : undefined"
                                :aria-describedby="error('evidence_reference') ? 'receipt-reference-error' : undefined"
                            />
                            <p v-if="error('evidence_reference')" id="receipt-reference-error" class="wd-field-error">
                                {{ error('evidence_reference') }}
                            </p>
                        </div>
                    </div>
                    <SelectField
                        id="receipt-correction"
                        v-model="form.correction_of"
                        label="Corrects a voided receipt (optional)"
                        :options="corrections"
                        :searchable="false"
                        :error="error('correction_of')"
                        hint="Each reference can be used once, including voided history."
                    />
                </fieldset>
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="close"
                    >Cancel</Button
                >
                <Button type="submit" class="wd-button wd-button-primary" :disabled="busy">{{
                    busy ? 'Saving…' : 'Record receipt'
                }}</Button>
            </footer>
        </form>
    </dialog>
</template>
