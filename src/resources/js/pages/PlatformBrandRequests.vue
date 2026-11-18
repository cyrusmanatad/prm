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
import { dateTime } from '../features/admin/format';
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
const props = defineProps<{
    requests: { total: number; pending: number; rows: Row[] };
    filters: Filters;
    page: number;
}>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/brand-requests', () => props.filters);
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
const columns = [
    { key: 'brand_name', label: 'Brand' },
    { key: 'applicant', label: 'Applicant' },
    { key: 'status', label: 'Status' },
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
        eyebrow="Governance"
    >
        <ContentSection title="Find requests">
            <FilterBar
                label="Filter Brand requests"
                :applied="applied"
                :pending="pending"
                @submit="submit"
                @clear="clear"
            >
                <FormField
                    id="brand-request-search"
                    v-model="draft.q"
                    label="Brand, applicant name, email or request ID"
                    :required="false"
                    :maxlength="120"
                />
                <SelectField
                    id="brand-request-status"
                    v-model="draft.status"
                    label="Status"
                    :options="[
                        { value: '', label: 'Any' },
                        ...Object.entries(reviewStatuses).map(([value, entry]) => ({ value, label: entry.label })),
                    ]"
                />
                <SelectField
                    id="brand-request-source"
                    v-model="draft.source"
                    label="Source"
                    :options="[
                        { value: '', label: 'Any' },
                        ...Object.entries(brandSources).map(([value, label]) => ({ value, label })),
                    ]"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection
            title="Requests"
            description="Requests awaiting a decision come first, longest wait first. Others follow, most recent first."
        >
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
                caption="Brand workspace requests"
                :columns="columns"
                :rows="requests.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-brand_name="{ row }"
                    ><Link :href="`/admin/brand-requests/${row.id}`">{{ row.brand_name ?? 'Unnamed workspace' }}</Link
                    ><span class="wd-data-secondary">Submitted {{ dateTime(row.submitted_at) }}</span></template
                >
                <template #cell-applicant="{ row }"
                    ><template v-if="row.applicant"
                        >{{ row.applicant }}<span class="wd-data-secondary">{{ row.applicant_email }}</span></template
                    ><template v-else>No applicant: existing workspace</template></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge
                        :label="reviewStatuses[row.status]?.label ?? row.status"
                        :tone="reviewStatuses[row.status]?.tone ?? 'neutral'"
                /></template>
                <template #cell-source="{ row }">{{ brandSources[row.source] ?? row.source }}</template>
                <template #cell-updated_at="{ row }">{{ dateTime(row.updated_at) }}</template>
            </DataTable>
            <Pagination
                label="Brand request pages"
                :page="page"
                :per-page="25"
                :count="requests.rows.length"
                :total="requests.total"
                noun="requests"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
