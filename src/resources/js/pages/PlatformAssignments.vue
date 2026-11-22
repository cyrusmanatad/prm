<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import AssignmentTable from '../features/admin/AssignmentTable.vue';
import { useListFilters } from '../composables/useListFilters';
import { entitlementStates } from '../features/admin/format';
import type { AssignmentRow } from '../features/admin/types';

type Filters = { workspace: string; state: string; history: string };
type Facets = Record<'all' | 'current' | 'active' | 'scheduled' | 'expired' | 'superseded', number>;
const props = defineProps<{
    assignments: { total: number; rows: AssignmentRow[]; facets?: Facets };
    filters: Filters;
    page: number;
}>();
const { pending, clear, href } = useListFilters<Filters>('/admin/assignments', () => props.filters);
const workspaceName = computed(() => props.assignments.rows[0]?.workspace ?? props.filters.workspace);
const applied = computed(() => [
    ...(props.filters.workspace ? [{ label: 'Workspace', value: workspaceName.value }] : []),
    ...(props.filters.state
        ? [{ label: 'State', value: entitlementStates[props.filters.state]?.label ?? props.filters.state }]
        : []),
    ...(props.filters.history ? [{ label: 'History', value: 'Including superseded' }] : []),
]);
/** Views: each workspace's current assignment, one state, or the full append-only history. */
const views: Record<string, Pick<Filters, 'state' | 'history'>> = {
    '': { state: '', history: '' },
    active: { state: 'active', history: '' },
    scheduled: { state: 'scheduled', history: '' },
    expired: { state: 'expired', history: '' },
    superseded: { state: 'superseded', history: '' },
    history: { state: '', history: 'all' },
};
const currentView = computed(() =>
    props.filters.history ? (props.filters.state ? 'none' : 'history') : props.filters.state,
);
const chips = computed<FilterChip[]>(() => {
    const f = props.assignments.facets;
    return f
        ? [
              { value: '', label: 'Current', count: f.current },
              { value: 'active', label: entitlementStates.active!.label, count: f.active },
              { value: 'scheduled', label: entitlementStates.scheduled!.label, count: f.scheduled },
              { value: 'expired', label: entitlementStates.expired!.label, count: f.expired },
              { value: 'superseded', label: entitlementStates.superseded!.label, count: f.superseded },
              { value: 'history', label: 'Full history', count: f.all },
          ]
        : [];
});
</script>

<template>
    <AdminShell
        title="Assignments"
        description="Each workspace's selected assignment. History is append-only: a replacement is selected immediately, even when it starts in the future."
        compact
    >
        <section class="rec-card" aria-labelledby="assignments-heading">
            <header class="rec-card-head">
                <h2 id="assignments-heading">Assignments</h2>
                <span class="rec-count">{{ assignments.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · 25 per page · to record one, open a workspace</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Show assignments"
                    compact
                    :chips="chips"
                    :current="currentView"
                    :href-for="(view) => href(1, views[view] ?? views[''])"
                />
            </div>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!assignments.rows.length && applied.length"
                title="No assignments match these filters"
                description="Change or clear the filters to see other assignments."
            />
            <EmptyState
                v-else-if="!assignments.rows.length"
                title="No assignments have been recorded"
                description="Open a workspace and choose Record assignment to give it a plan."
                ><Link href="/admin/workspaces" class="wd-link inline-flex min-h-11 items-center"
                    >Go to workspaces</Link
                ></EmptyState
            >
            <AssignmentTable
                v-else
                caption="Assignments"
                :class="{ 'is-pending': pending }"
                :rows="assignments.rows"
                link-workspace
                compact
            />
            <div v-if="assignments.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Assignment pages"
                    :page="page"
                    :per-page="25"
                    :count="assignments.rows.length"
                    :total="assignments.total"
                    noun="assignments"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
