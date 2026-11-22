<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Ban, Pencil, RotateCcw } from '@lucide/vue';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import RowActionMenu, { type RowAction } from '../components/RowActionMenu.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { useListFilters } from '../composables/useListFilters';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import ListToolbar from '../features/admin/ListToolbar.vue';
import OperatorAccessDialog, { type Operator } from '../features/admin/OperatorAccessDialog.vue';
import { capabilityNames as labels } from '../features/admin/format';

type Candidate = { id: number; name: string; email: string; verified: boolean };
type Filters = { q: string; view: string };
type Facets = Record<'all' | 'active' | 'unenrolled' | 'revoked', number>;
const props = defineProps<{
    operators: { total: number; rows: Operator[]; facets: Facets };
    filters: Filters;
    page: number;
    /** Listed first after your own grant: the operator reached from an account page, or the one just changed. */
    pinned?: number | string | null;
    capabilities: string[];
    candidate: Candidate | null;
}>();
const dialog = ref<InstanceType<typeof OperatorAccessDialog>>();

/** Searched, filtered and paged on the server, 25 at a time. View counts follow the search but not the view. */
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/operators', () => props.filters);
const viewLabels: Record<string, string> = {
    active: 'Active',
    unenrolled: 'No authenticator',
    revoked: 'Revoked',
};
const chips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.operators.facets.all },
    ...(['active', 'unenrolled', 'revoked'] as const).map((value) => ({
        value,
        label: viewLabels[value]!,
        count: props.operators.facets[value],
    })),
]);
const applied = computed(() => (props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []));
const rows = computed(() => props.operators.rows);
const pinnedId = computed(() => (props.pinned ? Number(props.pinned) : null));

const existing = computed(() => rows.value.find((row) => row.user_id === props.candidate?.id));
// Arriving from an account page: open the grant for that identity when it can receive one.
onMounted(() => {
    if (props.candidate?.verified && (!existing.value || existing.value.revoked_at))
        dialog.value?.open('grant', props.candidate, existing.value);
});
const target = (row: Operator) => ({ id: row.user_id, name: row.name, email: row.email });
function actions(row: Operator): RowAction[] {
    if (row.revoked_at)
        return [
            {
                label: 'Grant again',
                icon: RotateCcw,
                select: (trigger) => dialog.value?.open('grant', target(row), row, trigger),
            },
        ];
    return [
        {
            label: 'Change capabilities',
            icon: Pencil,
            select: (trigger) => dialog.value?.open('change', target(row), row, trigger),
        },
        {
            label: 'Revoke platform access',
            icon: Ban,
            danger: true,
            separated: true,
            select: (trigger) => dialog.value?.open('revoke', target(row), row, trigger),
        },
    ];
}
const status = (row: Operator) =>
    row.suspended
        ? { label: 'Account suspended', tone: 'danger' as const }
        : row.revoked_at
          ? { label: 'Revoked', tone: 'neutral' as const }
          : { label: 'Active', tone: 'success' as const };
const columns = [
    { key: 'name', label: 'Operator' },
    { key: 'status', label: 'Status' },
    { key: 'permissions', label: 'Capabilities' },
    { key: 'mfa', label: 'Authenticator' },
    { key: 'actions', label: 'Actions' },
];
</script>

<template>
    <AdminShell
        title="Platform access"
        description="Who can open administration, and with which capabilities. A workspace role never grants platform access, and platform access never opens private workspace records."
        compact
    >
        <section v-if="candidate && !candidate.verified" class="rec-card adm-panel" aria-labelledby="grant-heading">
            <header class="rec-card-head"><h2 id="grant-heading">Grant platform access</h2></header>
            <UnavailableNotice
                title="This account cannot receive platform access yet"
                :reason="`${candidate.name} (${candidate.email}) has not verified their email address. Only a verified identity can hold a grant.`"
            />
        </section>
        <section class="rec-card" aria-labelledby="operators-heading">
            <header class="rec-card-head">
                <h2 id="operators-heading">Operators</h2>
                <span class="rec-count">{{ operators.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">To grant access to someone new, open their account under Accounts</p>
            </header>
            <div class="rec-chips">
                <FilterChips
                    label="Show operators"
                    compact
                    :chips="chips"
                    :current="filters.view"
                    :href-for="(view) => href(1, { view })"
                />
            </div>
            <ListToolbar
                id="operator"
                v-model:q="draft.q"
                label="Filter operators"
                search-label="Operator name or email"
                placeholder="Search name or email"
                :pending="pending"
                @submit="submit"
            />
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState v-if="!operators.facets.all && !applied.length" title="No platform grants exist" />
            <EmptyState
                v-else-if="!rows.length"
                title="No operators match"
                description="Change the search or choose another view."
            />
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Operators"
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.user_id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/accounts/${row.user_id}?tab=security`">{{
                        row.name
                    }}</Link
                    ><span class="rec-row-sub" :title="row.email">{{ row.email }}</span
                    ><span v-if="row.self" class="adm-row-note"
                        >This is you. You cannot change your own grant{{
                            row.last_owner ? '. You are the last usable platform owner' : ''
                        }}.</span
                    ><span v-else-if="row.user_id === pinnedId" class="adm-row-note"
                        >Pinned to the top of this list</span
                    ></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge :label="status(row).label" :tone="status(row).tone"
                /></template>
                <template #cell-permissions="{ row }"
                    ><span class="rec-ellipsis" :title="labels(row.permissions)">{{
                        labels(row.permissions)
                    }}</span></template
                >
                <template #cell-mfa="{ row }"
                    ><StatusBadge
                        :label="row.mfa_ready ? 'Ready' : 'Not enrolled'"
                        :tone="row.mfa_ready ? 'success' : 'warning'"
                /></template>
                <template #cell-actions="{ row }"
                    ><RowActionMenu v-if="!row.self" :label="`Actions for ${row.name}`" :actions="actions(row)"
                /></template>
            </DataTable>
            <div v-if="rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Operator pages"
                    :page="page"
                    :per-page="25"
                    :count="rows.length"
                    :total="operators.total"
                    noun="operators"
                    numbered
                    :href-for="href"
                />
            </div>
            <p class="rec-fine adm-panel-body">
                The first platform owner, and recovery of a lost authenticator, are handled by the offline procedure.
            </p>
        </section>
        <OperatorAccessDialog ref="dialog" :capabilities="capabilities" :operators="rows" />
    </AdminShell>
</template>
