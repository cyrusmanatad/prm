<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Ban, Link2, Plus, Undo2 } from '@lucide/vue';
import FinanceLayout from '../components/FinanceLayout.vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import RowActionMenu, { type RowAction } from '../components/RowActionMenu.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { confirmAction } from '../composables/useConfirmation';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import AllocateDialog from '../features/finance/AllocateDialog.vue';
import RecordReceiptDialog from '../features/finance/RecordReceiptDialog.vue';
import ReverseDialog from '../features/finance/ReverseDialog.vue';
import { dateLabel, formatMoney, percent, subtract } from '../features/finance/amounts';
import type { Allocation, OpenDraft, Payment, PaymentSummary } from '../features/finance/types';
import { useFinanceCommand } from '../features/finance/useFinanceCommand';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { name: string };
    payments: Payment[];
    nextCursor: string | null;
    openDrafts: OpenDraft[];
    summary: PaymentSummary;
    filters: { status: string; cursor: string | null };
    errors?: Record<string, string>;
    status?: string;
}>();
const { professional } = useWorkspaceExperience();
const listBase = computed(() => (professional.value ? '/professional/finance/payments' : '/finance/payments'));
const errors = computed(() => props.errors ?? {});
const { busy, send } = useFinanceCommand();
const recordDialog = ref<InstanceType<typeof RecordReceiptDialog>>();
const allocateDialog = ref<InstanceType<typeof AllocateDialog>>();
const reverseDialog = ref<InstanceType<typeof ReverseDialog>>();

const chips = computed<FilterChip[]>(() => [
    { value: 'all', label: 'All', count: props.summary.recorded + props.summary.voided },
    { value: 'unallocated', label: 'Unallocated', count: props.summary.unallocated },
    { value: 'voided', label: 'Voided', count: props.summary.voided },
]);
const chipHref = (value: string) => `${listBase.value}${value === 'all' ? '' : `?status=${value}`}`;
const pageHref = (cursor: string | null) => {
    const params = new URLSearchParams();
    if (props.filters.status !== 'all') params.set('status', props.filters.status);
    if (cursor) params.set('cursor', cursor);
    return `${listBase.value}${params.toString() ? `?${params}` : ''}`;
};
const columns: DataColumn[] = [
    { key: 'evidence_reference', label: 'Reference' },
    { key: 'received_on', label: 'Received' },
    { key: 'amount_minor', label: 'Amount', numeric: true },
    { key: 'allocated_minor', label: 'Allocated' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Actions' },
];
const voided = computed(() => props.payments.filter((p) => p.status === 'voided'));
const unallocated = (p: Payment) => subtract(p.amount_minor, p.allocated_minor);
const activeAllocations = (p: Payment) => p.allocations.filter((a) => !a.reversal_reason);
const canAllocate = (p: Payment) =>
    p.status === 'recorded' &&
    BigInt(unallocated(p)) > 0n &&
    props.openDrafts.some((draft) => draft.currency === p.currency);
const totals = (key: string) =>
    props.summary.currencies.map((row) => ({
        currency: row.currency,
        text: formatMoney(row[key as 'recorded_minor' | 'allocated_minor' | 'unallocated_minor'], row.currency),
    }));
const money = (p: Payment, minor: string) => formatMoney(minor, p.currency);

function record(data: Record<string, unknown>): void {
    send('/finance/payments', data, { onSuccess: () => recordDialog.value?.close() });
}
function allocate(payment: Payment, data: Record<string, unknown>): void {
    send(`/finance/payments/${payment.id}/allocate`, data, { onSuccess: () => allocateDialog.value?.close() });
}
function reverse(payment: Payment, allocation: Allocation, reason: string, trigger: HTMLElement | null): void {
    confirmAction(
        'Reverse allocation',
        `Reverse ${money(payment, allocation.amount_minor)} from receipt ${payment.evidence_reference} allocated to ${allocation.recipient_name ?? 'a draft'}? Reason: ${reason}. The reconciliation history is preserved.`,
        () => send(`/finance/allocations/${allocation.id}/reverse`, { reason }),
        trigger ?? undefined,
        { tone: 'danger' },
    );
}
function voidReceipt(payment: Payment, trigger: HTMLElement): void {
    confirmAction(
        'Void receipt',
        `Void receipt ${payment.evidence_reference}? It can no longer be allocated. Its history is preserved.`,
        () => send(`/finance/payments/${payment.id}/void`, { version: payment.version }),
        trigger,
        { tone: 'danger' },
    );
}
function actions(payment: Payment): RowAction[] {
    if (payment.status !== 'recorded')
        return [
            {
                label: 'Record correction',
                icon: Plus,
                select: (trigger) => recordDialog.value?.open(trigger, payment),
            },
        ];
    const list: RowAction[] = [];
    if (canAllocate(payment))
        list.push({
            label: 'Allocate to a draft',
            icon: Link2,
            select: (trigger) => allocateDialog.value?.open(trigger, payment),
        });
    for (const allocation of activeAllocations(payment))
        list.push({
            label: `Reverse allocation to ${allocation.recipient_name ?? 'draft'}`,
            detail: money(payment, allocation.amount_minor),
            icon: Undo2,
            select: (trigger) => reverseDialog.value?.open(trigger, payment, allocation),
        });
    list.push({
        label: 'Void receipt',
        icon: Ban,
        danger: true,
        separated: list.length > 0,
        disabled: activeAllocations(payment).length > 0,
        select: (trigger) => voidReceipt(payment, trigger),
    });
    return list;
}
</script>
<template>
    <Head title="Manual payment records" />
    <FinanceLayout
        :account-name="account.name"
        :account-email="account.email"
        title="Manual payment records"
        active="payments"
        :workspace-name="workspace.name"
        :status="status"
        :errors="errors.version ? { version: errors.version } : {}"
    >
        <template #description
            >Record receipts you already received and match them to drafts. Nothing is charged.</template
        >
        <template #error-action><Link class="wd-link" :href="pageHref(filters.cursor)">Reload records</Link></template>
        <template #actions>
            <button
                type="button"
                class="wd-table-button rec-primary"
                @click="recordDialog?.open($event.currentTarget as HTMLElement)"
            >
                <Plus :size="16" aria-hidden="true" />Record receipt
            </button>
        </template>
        <ul class="rec-stats" aria-label="Receipt summary">
            <li
                v-for="[key, label, note] in [
                    ['recorded_minor', 'Recorded', `${summary.recorded} receipts · voided not counted`],
                    ['allocated_minor', 'Allocated', 'Matched to drafts'],
                    [
                        'unallocated_minor',
                        'Unallocated',
                        `${summary.unallocated} ${summary.unallocated === 1 ? 'receipt' : 'receipts'} to match`,
                    ],
                ]"
                :key="key"
                :class="{ 'is-alert': key === 'unallocated_minor' && summary.unallocated }"
            >
                <span>{{ label }}</span>
                <strong v-for="row in totals(key!)" :key="row.currency">{{ row.text }}</strong>
                <strong v-if="!summary.currencies.length">—</strong>
                <small>{{ note }}</small>
            </li>
            <li>
                <span>Voided</span><strong>{{ summary.voided }}</strong
                ><small>History kept</small>
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="receipts-heading">
            <header class="rec-card-head">
                <h2 id="receipts-heading">Receipts</h2>
                <span class="rec-count">{{ summary.recorded + summary.voided }}</span>
                <p class="rec-note">Newest first · 25 per page</p>
            </header>
            <div class="rec-chips">
                <FilterChips
                    label="Show receipts"
                    :chips="chips"
                    :current="filters.status"
                    :href-for="chipHref"
                    compact
                />
            </div>
            <DataTable
                v-if="payments.length"
                class="rec-table fin-payments-table"
                caption="Receipts"
                :columns="columns"
                :rows="payments"
                :row-key="(row) => row.id"
            >
                <template #cell-evidence_reference="{ row }">
                    <span class="rec-row-name">{{ row.evidence_reference }}</span>
                    <span v-for="allocation in row.allocations" :key="allocation.id" class="rec-row-sub"
                        ><template v-if="allocation.reversal_reason"
                            ><s
                                >→ {{ allocation.recipient_name ?? 'Draft' }}
                                {{ money(row, allocation.amount_minor) }}</s
                            >
                            · reversed: {{ allocation.reversal_reason }}</template
                        ><template v-else
                            >→
                            <Link :href="`/finance/invoices/${allocation.invoice_id}`">{{
                                allocation.recipient_name ?? 'Open allocated draft'
                            }}</Link>
                            {{ money(row, allocation.amount_minor) }}</template
                        ></span
                    >
                    <span v-if="row.correction_of" class="rec-row-sub">Corrects a voided receipt</span>
                    <span v-if="!row.allocations.length && row.status === 'recorded'" class="rec-row-sub"
                        >Not matched yet</span
                    >
                </template>
                <template #cell-received_on="{ row }">{{ dateLabel(row.received_on) }}</template>
                <template #cell-amount_minor="{ row }"
                    ><span
                        :class="{ 'fin-struck': row.status !== 'recorded' }"
                        :title="`${row.amount_minor} minor units`"
                        >{{ money(row, row.amount_minor) }}</span
                    ></template
                >
                <template #cell-allocated_minor="{ row }">
                    <span v-if="row.status === 'recorded'" class="fin-progress">
                        <span class="fin-bar" aria-hidden="true"
                            ><i :style="{ width: `${percent(row.allocated_minor, row.amount_minor)}%` }"
                        /></span>
                        <small>{{
                            unallocated(row) === '0'
                                ? 'Fully allocated'
                                : row.allocated_minor === '0'
                                  ? `${money(row, row.amount_minor)} unallocated`
                                  : `Allocated ${money(row, row.allocated_minor)} · ${money(row, unallocated(row))} left`
                        }}</small>
                    </span>
                    <span v-else class="fin-muted">—</span>
                </template>
                <template #cell-status="{ row }"
                    ><StatusBadge
                        :label="row.status === 'recorded' ? 'Recorded' : 'Voided'"
                        :tone="row.status === 'recorded' ? 'success' : 'neutral'"
                /></template>
                <template #cell-actions="{ row }">
                    <span class="rec-actions">
                        <button
                            v-if="canAllocate(row)"
                            type="button"
                            class="wd-table-button rec-primary"
                            :disabled="busy"
                            v-bind="{ 'aria-label': `Allocate ${row.evidence_reference}` }"
                            @click="allocateDialog?.open($event.currentTarget as HTMLElement, row)"
                        >
                            Allocate
                        </button>
                        <RowActionMenu
                            :label="`Actions for ${row.evidence_reference}`"
                            :actions="actions(row)"
                            trigger-class="rec-ghost"
                        />
                    </span>
                </template>
            </DataTable>
            <EmptyState
                v-else
                :title="filters.status === 'all' ? 'No receipts recorded yet' : 'No receipts here'"
                :description="
                    filters.status === 'all'
                        ? 'Record a receipt you already received, then match it to a draft.'
                        : 'Choose another filter to see the rest.'
                "
            />
            <footer class="rec-card-foot fin-foot">
                <span>Reversals and voids keep their history. Reverse a receipt’s allocations before voiding it.</span>
                <span class="fin-pages">
                    <Link v-if="filters.cursor" :href="pageHref(null)">Newest receipts</Link>
                    <Link v-if="nextCursor" :href="pageHref(nextCursor)">Older receipts</Link>
                </span>
            </footer>
        </section>
        <RecordReceiptDialog ref="recordDialog" :voided="voided" :errors="errors" :busy="busy" @save="record" />
        <AllocateDialog ref="allocateDialog" :drafts="openDrafts" :errors="errors" :busy="busy" @save="allocate" />
        <ReverseDialog ref="reverseDialog" :busy="busy" @confirm="reverse" />
    </FinanceLayout>
</template>
