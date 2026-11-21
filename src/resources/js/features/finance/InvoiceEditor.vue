<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Plus, X } from '@lucide/vue';
import DialogClose from '../../components/DialogClose.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { amountInput, formatAmount, formatMoney, multiply, parseAmount, validCurrency } from './amounts';
import type { Invoice } from './types';

/**
 * New draft or Edit draft. Prices are typed in the currency's units ("2,500.00") and sent as exact minor units;
 * line amounts and the total are calculated exactly with BigInt. Server errors arrive in `errors`.
 */
const props = defineProps<{ errors: Record<string, string>; busy: boolean; reload?: string }>();
const emit = defineEmits<{ save: [data: Record<string, unknown>, invoice: Invoice | null] }>();
const dialog = ref<HTMLDialogElement>();
const editing = ref<Invoice | null>(null);
type Line = { description: string; quantity: string; unit: string };
const form = ref({ recipient_name: '', currency: '', due_date: '', lines: [] as Line[] });
const initial = ref('');
const local = ref<Record<string, string>>({});
let trigger: HTMLElement | null = null;

const currency = computed(() => form.value.currency.trim().toUpperCase());
const lineMinor = (line: Line) => parseAmount(line.unit, currency.value);
const lineTotal = (line: Line) => {
    const unit = lineMinor(line);
    const quantity = Number(line.quantity);
    return unit !== null && Number.isInteger(quantity) && quantity >= 1 ? multiply(quantity, unit) : null;
};
const total = computed(() => {
    const totals = form.value.lines.map(lineTotal);
    return totals.every((value) => value !== null)
        ? totals.reduce((sum, value) => (BigInt(sum) + BigInt(value!)).toString(), '0')
        : null;
});
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);
const error = (key: string) => local.value[key] ?? props.errors[key];
/** Server messages that belong to no single field, such as an inactive workspace or a reached quota. */
const general = computed(() =>
    Object.entries(props.errors)
        .filter(([key]) => !/^(recipient_name|currency|due_date|lines\..+|version)$/.test(key))
        .map(([, message]) => message),
);

function open(source: HTMLElement | null, invoice?: Invoice): void {
    trigger = source;
    editing.value = invoice ?? null;
    form.value = invoice
        ? {
              recipient_name: invoice.recipient_name,
              currency: invoice.currency,
              due_date: invoice.due_date ?? '',
              lines: (invoice.lines ?? []).map((line) => ({
                  description: line.description,
                  quantity: String(line.quantity),
                  unit: amountInput(line.unit_price_minor, invoice.currency),
              })),
          }
        : { recipient_name: '', currency: '', due_date: '', lines: [{ description: '', quantity: '1', unit: '' }] };
    initial.value = JSON.stringify(form.value);
    local.value = {};
    dialog.value?.showModal();
    nextTick(() => dialog.value?.querySelector<HTMLElement>('#invoice-recipient')?.focus());
}
function close(): void {
    dialog.value?.close();
}
function closed(): void {
    trigger?.focus();
    trigger = null;
}
function addLine(): void {
    form.value.lines.push({ description: '', quantity: '1', unit: '' });
    nextTick(() => dialog.value?.querySelector<HTMLElement>(`#invoice-line-${form.value.lines.length - 1}`)?.focus());
}
function removeLine(index: number): void {
    form.value.lines.splice(index, 1);
    nextTick(() => dialog.value?.querySelector<HTMLElement>(`#invoice-line-${Math.max(0, index - 1)}`)?.focus());
}
function save(): void {
    const errors: Record<string, string> = {};
    if (!form.value.recipient_name.trim()) errors.recipient_name = 'Enter the recipient business name.';
    if (!validCurrency(currency.value)) errors.currency = 'Enter a three-letter currency code, such as USD.';
    form.value.lines.forEach((line, index) => {
        if (!line.description.trim()) errors[`lines.${index}.description`] = 'Describe this line.';
        const quantity = Number(line.quantity);
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 1000000)
            errors[`lines.${index}.quantity`] = 'Use a whole number from 1.';
        if (validCurrency(currency.value) && lineMinor(line) === null)
            errors[`lines.${index}.unit_price_minor`] =
                `Enter an amount such as ${amountInput('250000', currency.value)}.`;
    });
    local.value = errors;
    if (Object.keys(errors).length) {
        nextTick(() => dialog.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        return;
    }
    emit(
        'save',
        {
            recipient_name: form.value.recipient_name.trim(),
            currency: currency.value,
            due_date: form.value.due_date || null,
            lines: form.value.lines.map((line) => ({
                description: line.description.trim(),
                quantity: Number(line.quantity),
                unit_price_minor: lineMinor(line)!,
            })),
            ...(editing.value ? { version: editing.value.version } : {}),
        },
        editing.value,
    );
}
defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog fin-editor"
        aria-labelledby="invoice-editor-title"
        @close="closed"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="invoice-editor-title">{{ editing ? 'Edit draft' : 'New draft' }}</h2>
                <p class="rec-dialog-sub">
                    Private and never sent. Amounts are exact in the draft’s currency; no tax or conversion.
                </p>
            </div>
            <DialogClose label="Close draft editor" :disabled="busy" @click="close" />
        </header>
        <form class="rec-dialog-form" novalidate :aria-busy="busy" @submit.prevent="save">
            <div class="rec-dialog-body">
                <div v-if="errors.version" class="wd-banner rec-banner" role="alert">
                    <p>{{ errors.version }}</p>
                    <a v-if="reload" class="wd-link" :href="reload">Reload draft</a>
                </div>
                <div v-if="general.length" class="wd-banner rec-banner" role="alert">
                    <p v-for="message in general" :key="message">{{ message }}</p>
                </div>
                <fieldset class="rec-fields" :disabled="busy || !!errors.version">
                    <legend class="wd-sr-only">Draft details</legend>
                    <div class="fin-editor-top">
                        <div class="wd-field">
                            <label for="invoice-recipient">Recipient business name</label>
                            <input
                                id="invoice-recipient"
                                v-model="form.recipient_name"
                                maxlength="160"
                                autocomplete="organization"
                                :aria-invalid="error('recipient_name') ? 'true' : undefined"
                                :aria-describedby="error('recipient_name') ? 'invoice-recipient-error' : undefined"
                            />
                            <p v-if="error('recipient_name')" id="invoice-recipient-error" class="wd-field-error">
                                {{ error('recipient_name') }}
                            </p>
                        </div>
                        <div class="wd-field">
                            <label for="invoice-currency">Currency code</label>
                            <input
                                id="invoice-currency"
                                v-model="form.currency"
                                maxlength="3"
                                placeholder="USD"
                                autocapitalize="characters"
                                :aria-invalid="error('currency') ? 'true' : undefined"
                                :aria-describedby="error('currency') ? 'invoice-currency-error' : undefined"
                            />
                            <p v-if="error('currency')" id="invoice-currency-error" class="wd-field-error">
                                {{ error('currency') }}
                            </p>
                        </div>
                        <div class="wd-field">
                            <label for="invoice-due">Due date</label>
                            <input
                                id="invoice-due"
                                v-model="form.due_date"
                                type="date"
                                :aria-invalid="error('due_date') ? 'true' : undefined"
                            />
                            <p v-if="error('due_date')" class="wd-field-error">{{ error('due_date') }}</p>
                        </div>
                    </div>
                    <table class="fin-lines-edit">
                        <caption class="wd-sr-only">
                            Line items
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Description</th>
                                <th scope="col">Qty</th>
                                <th scope="col">Unit price</th>
                                <th scope="col" class="fin-num">Amount</th>
                                <th scope="col"><span class="wd-sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index">
                                <td>
                                    <input
                                        :id="`invoice-line-${index}`"
                                        v-model="line.description"
                                        maxlength="240"
                                        :aria-label="`Line ${index + 1} description`"
                                        :aria-invalid="error(`lines.${index}.description`) ? 'true' : undefined"
                                    />
                                    <span v-if="error(`lines.${index}.description`)" class="wd-field-error">{{
                                        error(`lines.${index}.description`)
                                    }}</span>
                                </td>
                                <td>
                                    <input
                                        v-model="line.quantity"
                                        inputmode="numeric"
                                        :aria-label="`Line ${index + 1} quantity`"
                                        :aria-invalid="error(`lines.${index}.quantity`) ? 'true' : undefined"
                                    />
                                    <span v-if="error(`lines.${index}.quantity`)" class="wd-field-error">{{
                                        error(`lines.${index}.quantity`)
                                    }}</span>
                                </td>
                                <td>
                                    <input
                                        v-model="line.unit"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        :aria-label="`Line ${index + 1} unit price`"
                                        :aria-invalid="error(`lines.${index}.unit_price_minor`) ? 'true' : undefined"
                                    />
                                    <span v-if="error(`lines.${index}.unit_price_minor`)" class="wd-field-error">{{
                                        error(`lines.${index}.unit_price_minor`)
                                    }}</span>
                                </td>
                                <td class="fin-num">
                                    {{ lineTotal(line) !== null ? formatAmount(lineTotal(line)!, currency) : '—' }}
                                </td>
                                <td>
                                    <button
                                        v-if="form.lines.length > 1"
                                        type="button"
                                        class="wd-table-icon-button rec-ghost"
                                        :aria-label="`Remove line ${index + 1}`"
                                        @click="removeLine(index)"
                                    >
                                        <X :size="15" aria-hidden="true" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="fin-editor-total">
                        <button v-if="form.lines.length < 50" type="button" class="wd-table-button" @click="addLine">
                            <Plus :size="15" aria-hidden="true" />Add line
                        </button>
                        <p>
                            <span>Total</span
                            ><strong>{{
                                total !== null && validCurrency(currency) ? formatMoney(total, currency) : '—'
                            }}</strong>
                        </p>
                    </div>
                    <p v-if="total !== null && validCurrency(currency)" class="rec-fine fin-exact">
                        Exact total: {{ total }} minor units · up to 50 lines.
                    </p>
                </fieldset>
            </div>
            <footer class="rec-dialog-foot rec-sticky">
                <p v-if="dirty" class="rec-dirty" role="status"><span aria-hidden="true"></span>Unsaved changes</p>
                <p v-else class="rec-clean">No unsaved changes</p>
                <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="close"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="wd-button wd-button-primary"
                    :disabled="busy || !dirty || !!errors.version"
                    >{{ busy ? 'Saving…' : 'Save draft' }}</Button
                >
            </footer>
        </form>
    </dialog>
</template>
