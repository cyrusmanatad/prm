<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterBar from '../components/FilterBar.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import AssignmentTable from '../features/admin/AssignmentTable.vue';
import { useListFilters } from '../composables/useListFilters';
import { entitlementStates } from '../features/admin/format';
import type { AssignmentRow } from '../features/admin/types';

type Filters = { workspace: string; state: string; history: string };
const props = defineProps<{ assignments: { total: number; rows: AssignmentRow[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/assignments', () => props.filters);
const workspaceName = computed(() => props.assignments.rows[0]?.workspace ?? props.filters.workspace);
const applied = computed(() => [
    ...(props.filters.workspace ? [{ label: 'Workspace', value: workspaceName.value }] : []),
    ...(props.filters.state
        ? [{ label: 'State', value: entitlementStates[props.filters.state]?.label ?? props.filters.state }]
        : []),
    ...(props.filters.history ? [{ label: 'History', value: 'Including superseded' }] : []),
]);
</script>

<template>
    <AdminShell
        title="Assignments"
        description="Each workspace's selected assignment. History is append-only: a replacement is selected immediately, even when it starts in the future."
        eyebrow="Entitlements"
    >
        <ContentSection title="Find assignments">
            <FilterBar label="Filter assignments" :applied="applied" :pending="pending" @submit="submit" @clear="clear">
                <SelectField
                    id="assignment-state"
                    v-model="draft.state"
                    label="State"
                    :options="[
                        { value: '', label: 'Any' },
                        ...['active', 'scheduled', 'expired', 'superseded'].map((value) => ({
                            value,
                            label: entitlementStates[value]!.label,
                        })),
                    ]"
                />
                <SelectField
                    id="assignment-history"
                    v-model="draft.history"
                    label="History"
                    :options="[
                        { value: '', label: 'Selected assignments only' },
                        { value: 'all', label: 'Include superseded' },
                    ]"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Assignments">
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
            <AssignmentTable v-else caption="Assignments" :rows="assignments.rows" link-workspace />
            <Pagination
                label="Assignment pages"
                :page="page"
                :per-page="25"
                :count="assignments.rows.length"
                :total="assignments.total"
                noun="assignments"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
