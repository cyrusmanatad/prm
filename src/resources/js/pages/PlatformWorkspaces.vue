<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterBar from '../components/FilterBar.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useListFilters } from '../composables/useListFilters';
import { dateTime, entitlementStates, workspaceTypes } from '../features/admin/format';

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
type Filters = { q: string; kind: string; state: string; access: string };
const props = defineProps<{ workspaces: { total: number; rows: Workspace[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/workspaces', () => props.filters);
const stateOptions = ['active', 'scheduled', 'expired', 'unassigned-development', 'pending-activation'];
const applied = computed(() => [
    ...(props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []),
    ...(props.filters.kind ? [{ label: 'Type', value: workspaceTypes[props.filters.kind] ?? props.filters.kind }] : []),
    ...(props.filters.state
        ? [{ label: 'Entitlement', value: entitlementStates[props.filters.state]?.label ?? props.filters.state }]
        : []),
    ...(props.filters.access
        ? [{ label: 'Access', value: props.filters.access === 'suspended' ? 'Suspended' : 'Active' }]
        : []),
]);
const columns = [
    { key: 'name', label: 'Workspace' },
    { key: 'kind', label: 'Type' },
    { key: 'access', label: 'Access' },
    { key: 'owners', label: 'Owners' },
    { key: 'members', label: 'Active members', numeric: true },
    { key: 'entitlement_state', label: 'Entitlement' },
    { key: 'created_at', label: 'Created' },
];
</script>

<template>
    <AdminShell
        title="Workspaces"
        description="Creator and brand workspaces with ownership, membership and entitlement state. Leads, contacts, documents and finance stay private to each workspace."
        eyebrow="Directory"
    >
        <ContentSection title="Find workspaces">
            <FilterBar label="Filter workspaces" :applied="applied" :pending="pending" @submit="submit" @clear="clear">
                <FormField
                    id="workspace-search"
                    v-model="draft.q"
                    label="Name or workspace ID"
                    :required="false"
                    :maxlength="120"
                />
                <SelectField
                    id="workspace-kind"
                    v-model="draft.kind"
                    label="Type"
                    :options="[
                        { value: '', label: 'Any' },
                        { value: 'creator', label: 'Creator' },
                        { value: 'brand', label: 'Brand' },
                    ]"
                />
                <SelectField
                    id="workspace-state"
                    v-model="draft.state"
                    label="Entitlement"
                    :options="[
                        { value: '', label: 'Any' },
                        ...stateOptions.map((value) => ({ value, label: entitlementStates[value]!.label })),
                    ]"
                />
                <SelectField
                    id="workspace-access"
                    v-model="draft.access"
                    label="Access"
                    :options="[
                        { value: '', label: 'Any' },
                        { value: 'active', label: 'Active' },
                        { value: 'suspended', label: 'Suspended' },
                    ]"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Workspaces">
            <EmptyState
                v-if="!workspaces.rows.length && applied.length"
                title="No workspaces match these filters"
                description="Change or clear the filters to see other workspaces."
            />
            <EmptyState v-else-if="!workspaces.rows.length" title="No workspaces have been created yet" />
            <DataTable
                v-else
                caption="Workspaces"
                :columns="columns"
                :rows="workspaces.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/workspaces/${row.id}`">{{ row.name }}</Link></template
                >
                <template #cell-kind="{ row }"
                    >{{ workspaceTypes[row.kind] ?? row.kind
                    }}<span v-if="row.experience === 'professional'" class="wd-data-secondary"
                        >Professional</span
                    ></template
                >
                <template #cell-access="{ row }"
                    ><StatusBadge
                        :label="row.suspended ? 'Suspended' : 'Active'"
                        :tone="row.suspended ? 'danger' : 'success'"
                /></template>
                <template #cell-owners="{ row }"
                    ><span v-if="!row.owner_count" class="wd-data-secondary">No active owner</span
                    ><template v-else
                        >{{ row.owners.join(', ')
                        }}<span v-if="row.owner_count > row.owners.length" class="wd-data-secondary"
                            >and {{ row.owner_count - row.owners.length }} more</span
                        ></template
                    ></template
                >
                <template #cell-entitlement_state="{ row }"
                    ><StatusBadge
                        :label="entitlementStates[row.entitlement_state]?.label ?? row.entitlement_state"
                        :tone="entitlementStates[row.entitlement_state]?.tone ?? 'neutral'"
                    /><span v-if="row.plan" class="wd-data-secondary">{{ row.plan }}</span></template
                >
                <template #cell-created_at="{ row }">{{ dateTime(row.created_at) }}</template>
            </DataTable>
            <Pagination
                label="Workspace pages"
                :page="page"
                :per-page="25"
                :count="workspaces.rows.length"
                :total="workspaces.total"
                noun="workspaces"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
