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
import { auditOutcomes, auditSources, dateTime } from '../features/admin/format';

export type AuditRow = {
    source: string;
    id: string;
    occurred_at: string;
    actor: string;
    actor_email: string | null;
    event: string;
    target: string;
    outcome: string | null;
    workspace: string | null;
};
type Filters = { q: string; source: string; outcome: string; from: string; to: string };
const props = defineProps<{ events: { total: number; rows: AuditRow[] }; filters: Filters; page: number }>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/audit', () => props.filters);
const applied = computed(() => [
    ...(props.filters.q ? [{ label: 'Search', value: props.filters.q }] : []),
    ...(props.filters.source
        ? [{ label: 'Source', value: auditSources[props.filters.source] ?? props.filters.source }]
        : []),
    ...(props.filters.outcome
        ? [{ label: 'Outcome', value: auditOutcomes[props.filters.outcome]?.label ?? props.filters.outcome }]
        : []),
    ...(props.filters.from ? [{ label: 'From', value: props.filters.from }] : []),
    ...(props.filters.to ? [{ label: 'To', value: props.filters.to }] : []),
]);
const columns = [
    { key: 'event', label: 'Action' },
    { key: 'actor', label: 'Actor' },
    { key: 'target', label: 'Target' },
    { key: 'outcome', label: 'Outcome' },
    { key: 'occurred_at', label: 'Occurred' },
];
</script>

<template>
    <AdminShell
        title="Audit"
        description="Append-only record of administration actions and denied requests. Events cannot be edited, deleted or exported from here."
        eyebrow="Governance"
    >
        <ContentSection title="Find events">
            <FilterBar
                label="Filter audit events"
                :applied="applied"
                :pending="pending"
                @submit="submit"
                @clear="clear"
            >
                <FormField
                    id="audit-search"
                    v-model="draft.q"
                    label="Action, actor or exact target ID"
                    :required="false"
                    :maxlength="120"
                />
                <SelectField
                    id="audit-source"
                    v-model="draft.source"
                    label="Source"
                    :options="[
                        { value: '', label: 'Any' },
                        ...Object.entries(auditSources).map(([value, label]) => ({ value, label })),
                    ]"
                />
                <SelectField
                    id="audit-outcome"
                    v-model="draft.outcome"
                    label="Outcome"
                    :options="[
                        { value: '', label: 'Any' },
                        ...Object.entries(auditOutcomes).map(([value, entry]) => ({ value, label: entry.label })),
                    ]"
                />
                <DateRangeFields v-model:from="draft.from" v-model:to="draft.to" prefix="audit" />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Events" description="Newest first. Dates filter on calendar days in UTC.">
            <EmptyState
                v-if="!events.rows.length && applied.length"
                title="No events match these filters"
                description="Change or clear the filters to see other events."
            />
            <EmptyState v-else-if="!events.rows.length" title="No administration events have been recorded" />
            <DataTable
                v-else
                caption="Audit events"
                :columns="columns"
                :rows="events.rows"
                :row-key="(row) => `${row.source}-${row.id}`"
            >
                <template #cell-event="{ row }"
                    ><Link :href="`/admin/audit/${row.source}/${row.id}`">{{ row.event }}</Link
                    ><span class="wd-data-secondary">{{ auditSources[row.source] ?? row.source }}</span></template
                >
                <template #cell-actor="{ row }"
                    >{{ row.actor
                    }}<span v-if="row.actor_email" class="wd-data-secondary">{{ row.actor_email }}</span></template
                >
                <template #cell-target="{ row }"
                    >{{ row.target
                    }}<span v-if="row.workspace" class="wd-data-secondary">{{ row.workspace }}</span></template
                >
                <template #cell-outcome="{ row }"
                    ><StatusBadge
                        v-if="row.outcome"
                        :label="auditOutcomes[row.outcome]?.label ?? row.outcome"
                        :tone="auditOutcomes[row.outcome]?.tone ?? 'neutral'"
                    /><span v-else class="wd-data-secondary">Not recorded in this event source</span></template
                >
                <template #cell-occurred_at="{ row }">{{ dateTime(row.occurred_at) }}</template>
            </DataTable>
            <Pagination
                label="Audit pages"
                :page="page"
                :per-page="25"
                :count="events.rows.length"
                :total="events.total"
                noun="events"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
