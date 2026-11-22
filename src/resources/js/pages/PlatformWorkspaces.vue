<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useListFilters } from '../composables/useListFilters';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import ListToolbar from '../features/admin/ListToolbar.vue';
import { dateOnly, dateTime, entitlementStates, workspaceTypes } from '../features/admin/format';

type Workspace = {
    id: string;
    name: string;
    kind: string;
    experience: string;
    created_at: string;
    entitlement_state: string;
    members: number;
    owners: string[];
    owner_count: number;
    plan: string | null;
    suspended: boolean;
};
type Facets = { all: number; creator: number; brand: number };
type Filters = { q: string; kind: string; state: string; access: string; from: string; to: string };
const props = defineProps<{
    workspaces: { total: number; rows: Workspace[]; facets?: Facets };
    /** Older payloads may omit some filters. */
    filters: Partial<Filters>;
    page: number;
}>();
const filters = computed<Filters>(() => ({
    q: '',
    kind: '',
    state: '',
    access: '',
    from: '',
    to: '',
    ...props.filters,
}));
const { draft, pending, submit, apply, clear, href } = useListFilters<Filters>(
    '/admin/workspaces',
    () => filters.value,
);
const stateOptions = ['active', 'scheduled', 'expired', 'unassigned-development', 'pending-activation'];
const applied = computed(() => [
    ...(filters.value.q ? [{ label: 'Search', value: filters.value.q }] : []),
    ...(filters.value.kind ? [{ label: 'Type', value: workspaceTypes[filters.value.kind] ?? filters.value.kind }] : []),
    ...(filters.value.state
        ? [{ label: 'Entitlement', value: entitlementStates[filters.value.state]?.label ?? filters.value.state }]
        : []),
    ...(filters.value.access
        ? [{ label: 'Access', value: filters.value.access === 'suspended' ? 'Suspended' : 'Active' }]
        : []),
    ...(filters.value.from ? [{ label: 'Created from', value: filters.value.from }] : []),
    ...(filters.value.to ? [{ label: 'Created to', value: filters.value.to }] : []),
]);

/** Type chips; entitlement, access and dates combine with them. */
const chips = computed<FilterChip[]>(() => {
    const f = props.workspaces.facets;
    return f
        ? [
              { value: '', label: 'All', count: f.all },
              { value: 'creator', label: 'Creator', count: f.creator },
              { value: 'brand', label: 'Brand', count: f.brand },
          ]
        : [];
});
const toolbarFilters = computed(
    () => [filters.value.state, filters.value.access, filters.value.from, filters.value.to].filter(Boolean).length,
);
const columns = [
    { key: 'name', label: 'Workspace' },
    { key: 'status', label: 'Status' },
    { key: 'owners', label: 'Owners' },
    { key: 'members', label: 'Members', numeric: true },
    { key: 'entitlement_state', label: 'Entitlement' },
    { key: 'created_at', label: 'Created' },
];
const typeLabel = (row: Workspace) =>
    `${workspaceTypes[row.kind] ?? row.kind}${row.experience === 'professional' ? ' · Professional' : ''}`;
</script>

<template>
    <AdminShell
        title="Workspaces"
        description="Ownership, membership and entitlement state. Leads, contacts, documents and finance stay private to each workspace."
        compact
    >
        <section class="rec-card" aria-labelledby="workspaces-heading">
            <header class="rec-card-head">
                <h2 id="workspaces-heading">Workspaces</h2>
                <span class="rec-count">{{ workspaces.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">A–Z by name · 25 per page · created dates are UTC days</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Show workspaces"
                    compact
                    :chips="chips"
                    :current="filters.kind"
                    :href-for="(kind) => href(1, { kind })"
                />
            </div>
            <ListToolbar
                id="workspace"
                v-model:q="draft.q"
                v-model:from="draft.from"
                v-model:to="draft.to"
                label="Filter workspaces"
                search-label="Name or workspace ID"
                placeholder="Search name or workspace ID"
                :pending="pending"
                :active="toolbarFilters"
                :dates="{
                    noun: 'Created',
                    prefix: 'workspace-created',
                    fromLabel: 'Created from',
                    toLabel: 'Created to (inclusive)',
                }"
                :applied-from="filters.from"
                :applied-to="filters.to"
                @submit="submit"
            >
                <SelectField
                    id="workspace-state"
                    v-model="draft.state"
                    label="Entitlement"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any entitlement' },
                        ...stateOptions.map((value) => ({ value, label: entitlementStates[value]!.label })),
                    ]"
                    @change="(state) => apply({ state })"
                />
                <SelectField
                    id="workspace-access"
                    v-model="draft.access"
                    label="Access"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any access' },
                        { value: 'active', label: 'Active' },
                        { value: 'suspended', label: 'Suspended' },
                    ]"
                    @change="(access) => apply({ access })"
                />
            </ListToolbar>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!workspaces.rows.length && applied.length"
                title="No workspaces match these filters"
                description="Change or clear the filters to see other workspaces."
            />
            <EmptyState v-else-if="!workspaces.rows.length" title="No workspaces have been created yet" />
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Workspaces"
                :columns="columns"
                :rows="workspaces.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/workspaces/${row.id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub">{{ typeLabel(row) }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge
                        :label="row.suspended ? 'Suspended' : 'Active'"
                        :tone="row.suspended ? 'danger' : 'success'"
                /></template>
                <template #cell-owners="{ row }"
                    ><span v-if="!row.owner_count" class="rec-fine">No active owner</span
                    ><span v-else class="rec-ellipsis" :title="row.owners.join(', ')"
                        >{{ row.owners.join(', ')
                        }}<template v-if="row.owner_count > row.owners.length">
                            and {{ row.owner_count - row.owners.length }} more</template
                        ></span
                    ></template
                >
                <template #cell-members="{ row }"
                    >{{ row.members
                    }}<span class="adm-phone-only">&nbsp;{{ row.members === 1 ? 'member' : 'members' }}</span></template
                >
                <template #cell-entitlement_state="{ row }"
                    ><StatusBadge
                        :label="entitlementStates[row.entitlement_state]?.label ?? row.entitlement_state"
                        :tone="entitlementStates[row.entitlement_state]?.tone ?? 'neutral'"
                    /><span v-if="row.plan" class="rec-row-sub adm-phone-hidden">{{ row.plan }}</span></template
                >
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        dateOnly(row.created_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="workspaces.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Workspace pages"
                    :page="page"
                    :per-page="25"
                    :count="workspaces.rows.length"
                    :total="workspaces.total"
                    noun="workspaces"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
