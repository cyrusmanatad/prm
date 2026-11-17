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
import DateRangeFields from '../features/admin/DateRangeFields.vue';
import { dateTime } from '../features/admin/format';

type Account = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    workspaces: number;
    platform_access: boolean;
    suspended: boolean;
};
type Filters = { q: string; verified: string; grant: string; access: string; from: string; to: string };
const props = defineProps<{ accounts: { total: number; rows: Account[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/accounts', () => props.filters);
const applied = computed(() => [
    ...(props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []),
    ...(props.filters.verified
        ? [{ label: 'Verification', value: props.filters.verified === 'yes' ? 'Verified' : 'Unverified' }]
        : []),
    ...(props.filters.grant
        ? [{ label: 'Platform access', value: props.filters.grant === 'yes' ? 'Has a grant' : 'No grant' }]
        : []),
    ...(props.filters.access
        ? [{ label: 'Access', value: props.filters.access === 'suspended' ? 'Suspended' : 'Active' }]
        : []),
    ...(props.filters.from ? [{ label: 'Joined from', value: props.filters.from }] : []),
    ...(props.filters.to ? [{ label: 'Joined to', value: props.filters.to }] : []),
]);
const columns = [
    { key: 'name', label: 'Account' },
    { key: 'verification', label: 'Verification' },
    { key: 'access', label: 'Access' },
    { key: 'workspaces', label: 'Workspaces', numeric: true },
    { key: 'platform_access', label: 'Platform access' },
    { key: 'created_at', label: 'Joined' },
];
</script>

<template>
    <AdminShell
        title="Accounts"
        description="Every registered identity, with verification and membership counts. Passwords, sessions and private workspace records are never shown."
        eyebrow="Directory"
    >
        <ContentSection title="Find accounts">
            <FilterBar label="Filter accounts" :applied="applied" :pending="pending" @submit="submit" @clear="clear">
                <FormField
                    id="account-search"
                    v-model="draft.q"
                    label="Name, email or account ID"
                    :required="false"
                    :maxlength="120"
                />
                <SelectField
                    id="account-verified"
                    v-model="draft.verified"
                    label="Verification"
                    :options="[
                        { value: '', label: 'Any' },
                        { value: 'yes', label: 'Verified' },
                        { value: 'no', label: 'Unverified' },
                    ]"
                />
                <SelectField
                    id="account-grant"
                    v-model="draft.grant"
                    label="Platform access"
                    :options="[
                        { value: '', label: 'Any' },
                        { value: 'yes', label: 'Has a grant' },
                        { value: 'no', label: 'No grant' },
                    ]"
                />
                <SelectField
                    id="account-access"
                    v-model="draft.access"
                    label="Access"
                    :options="[
                        { value: '', label: 'Any' },
                        { value: 'active', label: 'Active' },
                        { value: 'suspended', label: 'Suspended' },
                    ]"
                />
                <DateRangeFields
                    v-model:from="draft.from"
                    v-model:to="draft.to"
                    prefix="account-joined"
                    from-label="Joined from"
                    to-label="Joined to (inclusive)"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Accounts" description="Joined dates filter on calendar days in UTC.">
            <EmptyState
                v-if="!accounts.rows.length && applied.length"
                title="No accounts match these filters"
                description="Change or clear the filters to see other accounts."
            />
            <EmptyState v-else-if="!accounts.rows.length" title="No accounts are registered yet" />
            <DataTable v-else caption="Accounts" :columns="columns" :rows="accounts.rows" :row-key="(row) => row.id">
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/accounts/${row.id}`">{{ row.name }}</Link
                    ><span class="wd-data-secondary">{{ row.email }}</span></template
                >
                <template #cell-verification="{ row }"
                    ><StatusBadge
                        :label="row.email_verified_at ? 'Verified' : 'Unverified'"
                        :tone="row.email_verified_at ? 'success' : 'warning'"
                /></template>
                <template #cell-access="{ row }"
                    ><StatusBadge
                        :label="row.suspended ? 'Suspended' : 'Active'"
                        :tone="row.suspended ? 'danger' : 'success'"
                /></template>
                <template #cell-platform_access="{ row }"
                    ><StatusBadge v-if="row.platform_access" label="Platform grant" /><span
                        v-else
                        class="wd-data-secondary"
                        >None</span
                    ></template
                >
                <template #cell-created_at="{ row }">{{ dateTime(row.created_at) }}</template>
            </DataTable>
            <Pagination
                label="Account pages"
                :page="page"
                :per-page="25"
                :count="accounts.rows.length"
                :total="accounts.total"
                noun="accounts"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
