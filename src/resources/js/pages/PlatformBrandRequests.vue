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
import { dateOnly, dateTime, recentTime } from '../features/admin/format';
import { brandSources, reviewStatuses, type BrandStatus } from '../features/brand-requests/types';

type Row = {
    id: string;
    source: string;
    status: BrandStatus;
    brand_name: string | null;
    submitted_at: string;
    updated_at: string;
    applicant: string | null;
    applicant_email: string | null;
};
type Filters = { q: string; status: string; source: string };
type Facets = Record<'all' | BrandStatus, number>;
const props = defineProps<{
    requests: { total: number; pending: number; rows: Row[]; facets?: Facets };
    filters: Filters;
    page: number;
}>();
const { draft, pending, submit, apply, clear, href } = useListFilters<Filters>(
    '/admin/brand-requests',
    () => props.filters,
);
const applied = computed(() => [
    ...(props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []),
    ...(props.filters.status
        ? [
              {
                  label: 'Status',
                  value: reviewStatuses[props.filters.status as BrandStatus]?.label ?? props.filters.status,
              },
          ]
        : []),
    ...(props.filters.source
        ? [{ label: 'Source', value: brandSources[props.filters.source] ?? props.filters.source }]
        : []),
]);
const chips = computed<FilterChip[]>(() => {
    const f = props.requests.facets;
    if (!f) return [];
    const order: BrandStatus[] = ['pending', 'needs_information', 'approved', 'declined'];
    return [
        { value: '', label: 'All', count: f.all },
        ...order.map((value) => ({ value, label: reviewStatuses[value].label, count: f[value] })),
    ];
});
const toolbarFilters = computed(() => (props.filters.source ? 1 : 0));
const columns = [
    { key: 'brand_name', label: 'Brand' },
    { key: 'status', label: 'Status' },
    { key: 'applicant', label: 'Applicant' },
    { key: 'source', label: 'Source' },
    { key: 'updated_at', label: 'Last change' },
];
const waiting = computed(() =>
    props.requests.pending
        ? `${props.requests.pending} ${props.requests.pending === 1 ? 'request is' : 'requests are'} awaiting a decision.`
        : 'No request is awaiting a decision.',
);
</script>

<template>
    <AdminShell
        title="Brand requests"
        :description="`Every new Brand workspace needs an approved request. ${waiting}`"
        compact
    >
        <section class="rec-card" aria-labelledby="brand-requests-heading">
            <header class="rec-card-head">
                <h2 id="brand-requests-heading">Requests</h2>
                <span class="rec-count">{{ requests.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Awaiting a decision first, longest wait first · then most recent</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Request status"
                    compact
                    :chips="chips"
                    :current="filters.status"
                    :href-for="(status) => href(1, { status })"
                />
            </div>
            <ListToolbar
                id="brand-request"
                v-model:q="draft.q"
                label="Filter Brand requests"
                search-label="Brand, applicant name, email or request ID"
                placeholder="Search Brand, applicant or request ID"
                :pending="pending"
                :active="toolbarFilters"
                @submit="submit"
            >
                <SelectField
                    id="brand-request-source"
                    v-model="draft.source"
                    label="Source"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any source' },
                        ...Object.entries(brandSources).map(([value, label]) => ({ value, label })),
                    ]"
                    @change="(source) => apply({ source })"
                />
            </ListToolbar>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!requests.rows.length && applied.length"
                title="No requests match these filters"
                description="Change or clear the filters to see other requests."
            />
            <EmptyState
                v-else-if="!requests.rows.length"
                title="No Brand workspace has been requested"
                description="Requests appear here when someone registers as a Brand or asks for a Brand workspace."
            />
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Brand workspace requests"
                :columns="columns"
                :rows="requests.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-brand_name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/brand-requests/${row.id}`">{{
                        row.brand_name ?? 'Unnamed workspace'
                    }}</Link
                    ><span class="rec-row-sub">Submitted {{ dateOnly(row.submitted_at) }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge
                        :label="reviewStatuses[row.status]?.label ?? row.status"
                        :tone="reviewStatuses[row.status]?.tone ?? 'neutral'"
                /></template>
                <template #cell-applicant="{ row }"
                    ><template v-if="row.applicant"
                        >{{ row.applicant
                        }}<span class="rec-row-sub adm-phone-hidden">{{ row.applicant_email }}</span></template
                    ><span v-else class="rec-fine">No applicant: existing workspace</span></template
                >
                <template #cell-source="{ row }">{{ brandSources[row.source] ?? row.source }}</template>
                <template #cell-updated_at="{ row }"
                    ><time :datetime="row.updated_at" :title="dateTime(row.updated_at)">{{
                        recentTime(row.updated_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="requests.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Brand request pages"
                    :page="page"
                    :per-page="25"
                    :count="requests.rows.length"
                    :total="requests.total"
                    noun="requests"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
