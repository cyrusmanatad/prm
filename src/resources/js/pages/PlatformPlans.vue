<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import { useListFilters } from '../composables/useListFilters';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import ListToolbar from '../features/admin/ListToolbar.vue';
import { dateOnly, dateTime } from '../features/admin/format';

type Plan = { id: string; name: string; version: number; created_at: string; selected_workspaces: number };
type Filters = { q: string };
const props = defineProps<{ plans: { total: number; rows: Plan[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/plans', () => props.filters);
const applied = computed(() => (props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []));
const columns = [
    { key: 'name', label: 'Plan' },
    { key: 'selected_workspaces', label: 'Workspaces using it now', numeric: true },
    { key: 'created_at', label: 'Created' },
];
</script>

<template>
    <AdminShell
        title="Plans"
        description="Immutable pilot plan versions. A version is never edited or deleted: recording an assignment with new limits creates the next version. No prices or billing are attached."
        compact
    >
        <section class="rec-card" aria-labelledby="plans-heading">
            <header class="rec-card-head">
                <h2 id="plans-heading">Plan versions</h2>
                <span class="rec-count">{{ plans.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · 25 per page</p>
            </header>
            <ListToolbar
                id="plan"
                v-model:q="draft.q"
                label="Filter plans"
                search-label="Plan name or ID"
                placeholder="Search plan name or ID"
                :pending="pending"
                @submit="submit"
            />
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
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
            <DataTable
                v-else
                class="rec-table adm-no-status"
                :class="{ 'is-pending': pending }"
                caption="Plan versions"
                :columns="columns"
                :rows="plans.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/plans/${row.id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub">Version {{ row.version }}</span></template
                >
                <template #cell-selected_workspaces="{ row }"
                    >{{ row.selected_workspaces
                    }}<span class="adm-phone-only"
                        >&nbsp;{{ row.selected_workspaces === 1 ? 'workspace' : 'workspaces' }} now</span
                    ></template
                >
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        dateOnly(row.created_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="plans.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Plan pages"
                    :page="page"
                    :per-page="25"
                    :count="plans.rows.length"
                    :total="plans.total"
                    noun="plan versions"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
