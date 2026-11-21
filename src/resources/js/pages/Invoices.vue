<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { Ban, Eye, Pencil, Plus } from '@lucide/vue';
import FinanceLayout from '../components/FinanceLayout.vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import RowActionMenu, { type RowAction } from '../components/RowActionMenu.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { confirmAction } from '../composables/useConfirmation';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import InvoiceDrawer from '../features/finance/InvoiceDrawer.vue';
import InvoiceEditor from '../features/finance/InvoiceEditor.vue';
import { dateLabel, formatMoney, percent, subtract, today } from '../features/finance/amounts';
import type { Invoice, InvoiceSummary } from '../features/finance/types';
import { useFinanceCommand } from '../features/finance/useFinanceCommand';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    invoice?: Invoice;
    invoices: { items: Invoice[]; nextCursor: string | null };
    summary: InvoiceSummary;
    filters: { status: string; cursor: string | null };
    errors?: Record<string, string>;
    status?: string;
}>();
const { professional } = useWorkspaceExperience();
const listBase = computed(() => (professional.value ? '/professional/finance/invoices' : '/finance/invoices'));
const paymentsBase = computed(() => (professional.value ? '/professional/finance/payments' : '/finance/payments'));
const query = (extra: Record<string, string | null> = {}) => {
    const params = new URLSearchParams();
    if (props.filters.status !== 'open') params.set('status', props.filters.status);
    for (const [key, value] of Object.entries(extra)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
};
const errors = computed(() => props.errors ?? {});
const { busy, send } = useFinanceCommand();
const editor = ref<InstanceType<typeof InvoiceEditor>>();

const chips = computed<FilterChip[]>(() => [
    { value: 'open', label: 'Open', count: props.summary.open },
    { value: 'voided', label: 'Voided', count: props.summary.voided },
    { value: 'all', label: 'All', count: props.summary.open + props.summary.voided },
]);
const chipHref = (value: string) => `${listBase.value}${value === 'open' ? '' : `?status=${value}`}`;
const columns: DataColumn[] = [
    { key: 'recipient_name', label: 'Recipient' },
    { key: 'due_date', label: 'Due' },
    { key: 'total_minor', label: 'Total', numeric: true },
    { key: 'received_minor', label: 'Received' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Actions' },
];
const balance = (row: Invoice) => subtract(row.total_minor, row.received_minor);
const pastDue = (row: Invoice) =>
    row.status === 'draft' && !!row.due_date && row.due_date < today() && BigInt(balance(row)) > 0n;
const totals = (key: string) =>
    props.summary.currencies.map((row) => ({
        currency: row.currency,
        text: formatMoney(row[key as 'total_minor' | 'received_minor' | 'balance_minor'], row.currency),
    }));

function openInvoice(row: Invoice): void {
    router.get(`/finance/invoices/${row.id}${query({ cursor: props.filters.cursor })}`, {}, { preserveScroll: true });
}
async function closeInvoice(): Promise<void> {
    const id = props.invoice?.id;
    router.get(
        `${listBase.value}${query({ cursor: props.filters.cursor })}`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => nextTick(() => document.querySelector<HTMLElement>(`[data-invoice="${id}"]`)?.focus()),
        },
    );
}
function save(data: Record<string, unknown>, invoice: Invoice | null): void {
    send(`/finance/invoices${invoice ? `/${invoice.id}` : ''}`, data, {
        method: invoice ? 'patch' : 'post',
        onSuccess: () => editor.value?.close(),
    });
}
function edit(row: Invoice, trigger: HTMLElement): void {
    if (props.invoice?.id === row.id) editor.value?.open(trigger, props.invoice);
    else router.get(`/finance/invoices/${row.id}${query()}`, {}, { preserveScroll: true });
}
function voidDraft(row: Invoice, source: Event | HTMLElement): void {
    confirmAction(
        'Void draft',
        `Void the draft for ${row.recipient_name}? Its history is preserved and it can no longer be edited.`,
        () => send(`/finance/invoices/${row.id}/void`, { version: row.version }),
        source,
        { tone: 'danger' },
    );
}
function actions(row: Invoice): RowAction[] {
    const list: RowAction[] = [{ label: 'Open', icon: Eye, select: () => openInvoice(row) }];
    if (row.status !== 'draft') return list;
    const locked = BigInt(row.received_minor) > 0n;
    list.push({ label: 'Edit', icon: Pencil, disabled: locked, select: (trigger) => edit(row, trigger) });
    list.push({
        label: 'Void draft',
        icon: Ban,
        danger: true,
        separated: true,
        disabled: locked,
        select: (trigger) => voidDraft(row, trigger),
    });
    return list;
}
</script>
<template>
    <Head title="Private invoice drafts" />
    <FinanceLayout
        :account-name="account.name"
        :account-email="account.email"
        title="Private invoice drafts"
        active="invoices"
        :workspace-name="workspace.name"
        :status="status"
        :errors="invoice || !errors.version ? {} : { version: errors.version }"
    >
        <template #description>Drafts are never issued or sent. No tax or currency conversion · owner only.</template>
        <template #actions>
            <button
                type="button"
                class="wd-table-button rec-primary"
                @click="editor?.open($event.currentTarget as HTMLElement)"
            >
                <Plus :size="16" aria-hidden="true" />New draft
            </button>
        </template>
        <ul class="rec-stats" aria-label="Invoice draft summary">
            <li>
                <span>Open drafts</span><strong>{{ summary.open }}</strong
                ><small>{{ summary.voided }} voided · not counted</small>
            </li>
            <li
                v-for="[key, label] in [
                    ['total_minor', 'Open total'],
                    ['received_minor', 'Received'],
                    ['balance_minor', 'Balance'],
                ]"
                :key="key"
                :class="{ 'is-alert': key === 'balance_minor' && summary.pastDue }"
            >
                <span>{{ label }}</span>
                <strong v-for="row in totals(key)" :key="row.currency">{{ row.text }}</strong>
                <strong v-if="!summary.currencies.length">—</strong>
                <small v-if="key === 'balance_minor'"
                    ><b v-if="summary.pastDue">{{ summary.pastDue }} past due</b
                    ><template v-else>Nothing past due</template></small
                >
                <small v-else-if="key === 'received_minor'">From allocated receipts</small>
                <small v-else>Across {{ summary.open }} open drafts</small>
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="drafts-heading">
            <header class="rec-card-head">
                <h2 id="drafts-heading">Drafts</h2>
                <span class="rec-count">{{ summary.open + summary.voided }}</span>
                <p class="rec-note">Newest first · 25 per page</p>
            </header>
            <div class="rec-chips">
                <FilterChips
                    label="Show drafts"
                    :chips="chips"
                    :current="filters.status"
                    :href-for="chipHref"
                    compact
                />
            </div>
            <DataTable
                v-if="invoices.items.length"
                class="rec-table fin-invoices-table"
                caption="Invoice drafts"
                :columns="columns"
                :rows="invoices.items"
                :row-key="(row) => row.id"
            >
                <template #cell-recipient_name="{ row }">
                    <button
                        type="button"
                        class="rec-row-title"
                        v-bind="{ 'data-invoice': row.id }"
                        @click="openInvoice(row)"
                    >
                        {{ row.recipient_name }}
                    </button>
                    <span class="rec-row-sub"
                        >{{ row.line_count }} {{ row.line_count === 1 ? 'line' : 'lines' }} · v{{ row.version }}</span
                    >
                </template>
                <template #cell-due_date="{ row }"
                    ><span :class="{ 'fin-past-due': pastDue(row) }"
                        >{{ dateLabel(row.due_date) }}<template v-if="pastDue(row)"> · past due</template></span
                    ></template
                >
                <template #cell-total_minor="{ row }"
                    ><span
                        :class="{ 'fin-struck': row.status !== 'draft' }"
                        :title="`${row.total_minor} minor units`"
                        >{{ formatMoney(row.total_minor, row.currency) }}</span
                    ></template
                >
                <template #cell-received_minor="{ row }">
                    <span v-if="row.status === 'draft'" class="fin-progress">
                        <span class="fin-bar" aria-hidden="true"
                            ><i :style="{ width: `${percent(row.received_minor, row.total_minor)}%` }"
                        /></span>
                        <small>{{
                            row.received_minor === '0'
                                ? 'Nothing received'
                                : balance(row) === '0'
                                  ? `${formatMoney(row.received_minor, row.currency)} · received in full`
                                  : `${formatMoney(row.received_minor, row.currency)} · balance ${formatMoney(balance(row), row.currency)}`
                        }}</small>
                    </span>
                    <span v-else class="fin-muted">—</span>
                </template>
                <template #cell-status="{ row }"
                    ><StatusBadge :label="row.status === 'draft' ? 'Draft' : 'Voided'" tone="neutral"
                /></template>
                <template #cell-actions="{ row }">
                    <RowActionMenu
                        :label="`Actions for ${row.recipient_name}`"
                        :actions="actions(row)"
                        trigger-class="rec-ghost"
                    />
                </template>
            </DataTable>
            <EmptyState
                v-else
                :title="filters.status === 'voided' ? 'No voided drafts' : 'No drafts yet'"
                :description="
                    filters.status === 'voided'
                        ? 'Voided drafts appear here with their history.'
                        : 'Create a private draft to track what a client owes.'
                "
            />
            <footer class="rec-card-foot fin-foot">
                <span>Amounts are exact minor units shown in each currency’s format.</span>
                <span class="fin-pages">
                    <Link v-if="filters.cursor" :href="`${listBase}${query()}`">Newest drafts</Link>
                    <Link v-if="invoices.nextCursor" :href="`${listBase}${query({ cursor: invoices.nextCursor })}`"
                        >Older drafts</Link
                    >
                </span>
            </footer>
        </section>
        <InvoiceDrawer
            v-if="invoice"
            :invoice="invoice"
            :busy="busy"
            :errors="errors"
            :reload="`/finance/invoices/${invoice.id}${query()}`"
            :payments-href="`${paymentsBase}?status=unallocated`"
            @close="closeInvoice"
            @edit="(trigger) => editor?.open(trigger, invoice)"
            @void="(event) => voidDraft(invoice!, event)"
        />
        <InvoiceEditor
            ref="editor"
            @save="save"
            :errors="errors"
            :busy="busy"
            :reload="invoice ? `/finance/invoices/${invoice.id}${query()}` : undefined"
        />
    </FinanceLayout>
</template>
