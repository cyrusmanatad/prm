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
import { dateOnly, dateTime } from '../features/admin/format';

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
type Facets = { all: number; active: number; suspended: number; unverified: number; granted: number };
type Filters = { q: string; verified: string; grant: string; access: string; from: string; to: string };
const props = defineProps<{
    accounts: { total: number; rows: Account[]; facets?: Facets };
    /** Older payloads may omit the access and date filters. */
    filters: Partial<Filters>;
    page: number;
}>();
const filters = computed<Filters>(() => ({
    q: '',
    verified: '',
    grant: '',
    access: '',
    from: '',
    to: '',
    ...props.filters,
}));
const { draft, pending, submit, apply, clear, href } = useListFilters<Filters>('/admin/accounts', () => filters.value);

const applied = computed(() => [
    ...(filters.value.q ? [{ label: 'Search', value: filters.value.q }] : []),
    ...(filters.value.verified
        ? [{ label: 'Verification', value: filters.value.verified === 'yes' ? 'Verified' : 'Unverified' }]
        : []),
    ...(filters.value.grant
        ? [{ label: 'Platform access', value: filters.value.grant === 'yes' ? 'Has a grant' : 'No grant' }]
        : []),
    ...(filters.value.access
        ? [{ label: 'Access', value: filters.value.access === 'suspended' ? 'Suspended' : 'Active' }]
        : []),
    ...(filters.value.from ? [{ label: 'Joined from', value: filters.value.from }] : []),
    ...(filters.value.to ? [{ label: 'Joined to', value: filters.value.to }] : []),
]);

/** Quick views. Each one sets a single access, verification or grant filter and keeps the search and dates. */
const views: Record<string, Pick<Filters, 'access' | 'verified' | 'grant'>> = {
    '': { access: '', verified: '', grant: '' },
    active: { access: 'active', verified: '', grant: '' },
    suspended: { access: 'suspended', verified: '', grant: '' },
    unverified: { access: '', verified: 'no', grant: '' },
    granted: { access: '', verified: '', grant: 'yes' },
};
const currentView = computed(
    () =>
        Object.entries(views).find(([, view]) =>
            (['access', 'verified', 'grant'] as const).every((key) => filters.value[key] === view[key]),
        )?.[0] ?? null,
);
const chips = computed<FilterChip[]>(() => {
    const f = props.accounts.facets;
    return f
        ? [
              { value: '', label: 'All', count: f.all },
              { value: 'active', label: 'Active', count: f.active },
              { value: 'suspended', label: 'Suspended', count: f.suspended },
              { value: 'unverified', label: 'Unverified', count: f.unverified },
              { value: 'granted', label: 'Platform grant', count: f.granted },
          ]
        : [];
});
const toolbarFilters = computed(
    () => [filters.value.verified, filters.value.grant, filters.value.from, filters.value.to].filter(Boolean).length,
);

/** One status per row: suspension outranks verification. The account page shows both. */
const status = (row: Account) =>
    row.suspended
        ? { label: 'Suspended', tone: 'danger' as const }
        : row.email_verified_at
          ? { label: 'Active', tone: 'success' as const }
          : { label: 'Unverified', tone: 'warning' as const };
const columns = [
    { key: 'name', label: 'Account' },
    { key: 'status', label: 'Status' },
    { key: 'workspaces', label: 'Workspaces', numeric: true },
    { key: 'platform_access', label: 'Platform access' },
    { key: 'created_at', label: 'Joined' },
];
</script>

<template>
    <AdminShell
        title="Accounts"
        description="Every registered identity. Passwords, sessions and private workspace records are never shown."
        compact
    >
        <section class="rec-card" aria-labelledby="accounts-heading">
            <header class="rec-card-head">
                <h2 id="accounts-heading">Accounts</h2>
                <span class="rec-count">{{ accounts.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">A–Z by name · 25 per page · joined dates are UTC days</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Show accounts"
                    compact
                    :chips="chips"
                    :current="currentView ?? 'none'"
                    :href-for="(view) => href(1, views[view] ?? views[''])"
                />
            </div>
            <ListToolbar
                id="account"
                v-model:q="draft.q"
                v-model:from="draft.from"
                v-model:to="draft.to"
                label="Filter accounts"
                search-label="Name, email or account ID"
                placeholder="Search name, email or account ID"
                :pending="pending"
                :active="toolbarFilters"
                :dates="{
                    noun: 'Joined',
                    prefix: 'account-joined',
                    fromLabel: 'Joined from',
                    toLabel: 'Joined to (inclusive)',
                }"
                :applied-from="filters.from"
                :applied-to="filters.to"
                @submit="submit"
            >
                <SelectField
                    id="account-verified"
                    v-model="draft.verified"
                    label="Verification"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any verification' },
                        { value: 'yes', label: 'Verified' },
                        { value: 'no', label: 'Unverified' },
                    ]"
                    @change="(verified) => apply({ verified })"
                />
                <SelectField
                    id="account-grant"
                    v-model="draft.grant"
                    label="Platform access"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any platform access' },
                        { value: 'yes', label: 'Has a grant' },
                        { value: 'no', label: 'No grant' },
                    ]"
                    @change="(grant) => apply({ grant })"
                />
            </ListToolbar>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!accounts.rows.length && applied.length"
                title="No accounts match these filters"
                description="Change or clear the filters to see other accounts."
            />
            <EmptyState v-else-if="!accounts.rows.length" title="No accounts are registered yet" />
            <DataTable
                v-else
                class="rec-table adm-records"
                :class="{ 'is-pending': pending }"
                caption="Accounts"
                :columns="columns"
                :rows="accounts.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/accounts/${row.id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub" :title="row.email">{{ row.email }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge :label="status(row).label" :tone="status(row).tone"
                /></template>
                <template #cell-workspaces="{ row }"
                    >{{ row.workspaces
                    }}<span class="adm-phone-only"
                        >&nbsp;{{ row.workspaces === 1 ? 'workspace' : 'workspaces' }}</span
                    ></template
                >
                <template #cell-platform_access="{ row }"
                    ><StatusBadge v-if="row.platform_access" label="Platform grant" /><span
                        v-else
                        class="rec-fine adm-phone-hidden"
                        ><span aria-hidden="true">—</span><span class="wd-sr-only">None</span></span
                    ></template
                >
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        dateOnly(row.created_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="accounts.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Account pages"
                    :page="page"
                    :per-page="25"
                    :count="accounts.rows.length"
                    :total="accounts.total"
                    noun="accounts"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
