<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Ban, Pencil } from '@lucide/vue';
import DialogClose from '../../components/DialogClose.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { dateLabel, formatAmount, formatMoney, subtract } from './amounts';
import type { Invoice } from './types';

/** One draft over the list: line items, receipts applied, balance and actions. Its address is /finance/invoices/{id}. */
const props = defineProps<{
    invoice: Invoice;
    busy: boolean;
    errors: Record<string, string>;
    reload: string;
    paymentsHref: string;
}>();
const emit = defineEmits<{ close: []; edit: [trigger: HTMLElement]; void: [event: Event] }>();
const dialog = ref<HTMLDialogElement>();
const balance = computed(() => subtract(props.invoice.total_minor, props.invoice.received_minor));
const active = computed(() => (props.invoice.allocations ?? []).filter((a) => !a.reversal_reason));
const reversed = computed(() => (props.invoice.allocations ?? []).filter((a) => a.reversal_reason));
const open = computed(() => props.invoice.status === 'draft');
const locked = computed(() => open.value && active.value.length > 0);
const money = (minor: string) => formatMoney(minor, props.invoice.currency);

async function show(): Promise<void> {
    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
    dialog.value?.querySelector<HTMLElement>('.wd-dialog-close')?.focus();
}
onMounted(show);
watch(() => props.invoice.id, show);
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-drawer"
        aria-labelledby="invoice-drawer-title"
        @cancel.prevent="emit('close')"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-drawer-head">
            <div class="rec-drawer-identity">
                <h2 id="invoice-drawer-title">{{ invoice.recipient_name }}</h2>
                <p class="rec-drawer-status">
                    <StatusBadge
                        :label="`${open ? 'Draft' : 'Voided'} · Version ${invoice.version}`"
                        :tone="open ? 'neutral' : 'neutral'"
                    />
                </p>
            </div>
            <DialogClose label="Close invoice draft" @click="emit('close')" />
        </header>
        <div class="rec-drawer-body rec-drawer-scroll" tabindex="0" role="region" aria-label="Draft details">
            <div v-if="errors.version" class="wd-banner rec-banner" role="alert">
                <p>{{ errors.version }}</p>
                <a class="wd-link" :href="reload">Reload draft</a>
            </div>
            <dl class="rec-facts">
                <dt>Due</dt>
                <dd>{{ dateLabel(invoice.due_date) }}</dd>
                <dt>Currency</dt>
                <dd>{{ invoice.currency }}</dd>
            </dl>
            <table class="fin-lines">
                <caption class="wd-sr-only">
                    Line items
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col" class="fin-num">Qty</th>
                        <th scope="col" class="fin-num">Unit</th>
                        <th scope="col" class="fin-num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in invoice.lines" :key="index">
                        <td>{{ line.description }}</td>
                        <td class="fin-num">{{ line.quantity }}</td>
                        <td class="fin-num">{{ formatAmount(line.unit_price_minor, invoice.currency) }}</td>
                        <td class="fin-num">{{ formatAmount(line.total_minor ?? '0', invoice.currency) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="3">Total</th>
                        <td class="fin-num" :title="`${invoice.total_minor} minor units`">
                            {{ money(invoice.total_minor) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
            <section v-if="open || active.length || reversed.length" aria-labelledby="invoice-receipts">
                <h3 id="invoice-receipts" class="fin-subhead">Receipts applied</h3>
                <ul class="fin-applied">
                    <li v-for="allocation in active" :key="allocation.id">
                        <span>{{ allocation.evidence_reference }} · {{ dateLabel(allocation.received_on) }}</span>
                        <strong>{{ money(allocation.amount_minor) }}</strong>
                    </li>
                    <li v-for="allocation in reversed" :key="allocation.id" class="is-reversed">
                        <span>{{ allocation.evidence_reference }} · reversed: {{ allocation.reversal_reason }}</span>
                        <s>{{ money(allocation.amount_minor) }}</s>
                    </li>
                    <li v-if="!active.length && !reversed.length" class="is-empty">Nothing received yet</li>
                    <li v-if="open" class="is-balance">
                        <span>Balance</span><strong>{{ money(balance) }}</strong>
                    </li>
                </ul>
            </section>
            <p v-if="locked" class="rec-fine">
                Reverse this draft’s allocations in Manual payments before editing or voiding it.
            </p>
            <p v-else-if="!open" class="rec-fine">Voided drafts keep their history and can’t be edited or allocated.</p>
        </div>
        <footer v-if="open" class="rec-drawer-foot">
            <button
                type="button"
                class="wd-table-button rec-danger"
                :disabled="busy || locked || !!errors.version"
                @click="emit('void', $event)"
            >
                <Ban :size="15" aria-hidden="true" />Void draft
            </button>
            <span class="rec-spacer"></span>
            <button
                type="button"
                class="wd-table-button"
                :disabled="busy || locked || !!errors.version"
                @click="emit('edit', $event.currentTarget as HTMLElement)"
            >
                <Pencil :size="15" aria-hidden="true" />Edit draft
            </button>
            <Link v-if="balance !== '0'" class="wd-table-button rec-primary" :href="paymentsHref">Match a receipt</Link>
        </footer>
    </dialog>
</template>
