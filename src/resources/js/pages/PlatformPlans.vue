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
import { useListFilters } from '../composables/useListFilters';
import { dateTime } from '../features/admin/format';

type Plan = { id: string; name: string; version: number; created_at: string; selected_workspaces: number };
type Filters = { q: string };
const props = defineProps<{ plans: { total: number; rows: Plan[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/plans', () => props.filters);
const applied = computed(() => (props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []));
const columns = [
    { key: 'name', label: 'Plan' },
    { key: 'version', label: 'Version', numeric: true },
    { key: 'selected_workspaces', label: 'Workspaces using it now', numeric: true },
    { key: 'created_at', label: 'Created' },
];
</script>

<template>
    <AdminShell
        title="Plans"
        description="Immutable pilot plan versions. A version is never edited or deleted: recording an assignment with new limits creates the next version. No prices or billing are attached."
        eyebrow="Entitlements"
    >
        <ContentSection title="Find plan versions">
            <FilterBar label="Filter plans" :applied="applied" :pending="pending" @submit="submit" @clear="clear">
                <FormField
                    id="plan-search"
                    v-model="draft.q"
                    label="Plan name or ID"
                    :required="false"
                    :maxlength="120"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Plan versions">
            <EmptyState
                v-if="!plans.rows.length && applied.length"
                title="No plan versions match this search"
                description="Change or clear the search to see other versions."
            />
            <EmptyState
                v-else-if="!plans.rows.length"
                title="No plan versions exist yet"
                description="A plan version is created when you record a workspace's first assignment. Open a workspace and choose Record assignment."
                ><Link href="/admin/workspaces" class="wd-link inline-flex min-h-11 items-center"
                    >Go to workspaces</Link
                ></EmptyState
            >
            <DataTable v-else caption="Plan versions" :columns="columns" :rows="plans.rows" :row-key="(row) => row.id">
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/plans/${row.id}`">{{ row.name }}</Link></template
                >
                <template #cell-created_at="{ row }">{{ dateTime(row.created_at) }}</template>
            </DataTable>
            <Pagination
                label="Plan pages"
                :page="page"
                :per-page="25"
                :count="plans.rows.length"
                :total="plans.total"
                noun="plan versions"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
