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
import {
    auditEventLabel,
    auditOutcomes,
    auditSources,
    auditTargetLabel,
    dateTime,
    recentTime,
} from '../features/admin/format';

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
    target_kind?: string | null;
    target_name?: string | null;
};
type Facets = { all: number; recorded: number; denied: number };
type Filters = { q: string; source: string; outcome: string; from: string; to: string };
const props = defineProps<{
    events: { total: number; rows: AuditRow[]; facets?: Facets };
    filters: Filters;
    page: number;
}>();
const { draft, pending, submit, apply, clear, href } = useListFilters<Filters>('/admin/audit', () => props.filters);
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
const chips = computed<FilterChip[]>(() => {
    const f = props.events.facets;
    return f
        ? [
              { value: '', label: 'All', count: f.all },
              { value: 'recorded', label: auditOutcomes.recorded!.label, count: f.recorded },
              { value: 'denied', label: auditOutcomes.denied!.label, count: f.denied },
          ]
        : [];
});
const toolbarFilters = computed(
    () => [props.filters.source, props.filters.from, props.filters.to].filter(Boolean).length,
);
const columns = [
    { key: 'event', label: 'Action' },
    { key: 'actor', label: 'Actor' },
    { key: 'target', label: 'Target' },
    { key: 'status', label: 'Status' },
    { key: 'occurred_at', label: 'When' },
];
/** Platform events name their target like the Overview; local pilot events keep the subject and workspace. */
const target = (row: AuditRow) =>
    row.source === 'platform' ? auditTargetLabel(row.target, row.target_kind, row.target_name) : row.target;
</script>

<template>
    <AdminShell
        title="Audit"
        description="Append-only record of administration actions and denied requests. Events cannot be edited, deleted or exported from here."
        compact
    >
        <section class="rec-card" aria-labelledby="audit-heading">
            <header class="rec-card-head">
                <h2 id="audit-heading">Events</h2>
                <span class="rec-count">{{ events.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · 25 per page · dates are UTC days</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Outcome"
                    compact
                    :chips="chips"
                    :current="filters.outcome"
                    :href-for="(outcome) => href(1, { outcome })"
                />
            </div>
            <ListToolbar
                id="audit"
                v-model:q="draft.q"
                v-model:from="draft.from"
                v-model:to="draft.to"
                label="Filter audit events"
                search-label="Action, actor or exact target ID"
                placeholder="Search action, actor or exact target ID"
                :pending="pending"
                :active="toolbarFilters"
                :dates="{ noun: 'Dates', prefix: 'audit', fromLabel: 'From date', toLabel: 'To date (inclusive)' }"
                :applied-from="filters.from"
                :applied-to="filters.to"
                @submit="submit"
            >
                <SelectField
                    id="audit-source"
                    v-model="draft.source"
                    label="Source"
                    :searchable="false"
                    :options="[
                        { value: '', label: 'Any source' },
                        ...Object.entries(auditSources).map(([value, label]) => ({ value, label })),
                    ]"
                    @change="(source) => apply({ source })"
                />
            </ListToolbar>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!events.rows.length && applied.length"
                title="No events match these filters"
                description="Change or clear the filters to see other events."
            />
            <EmptyState v-else-if="!events.rows.length" title="No administration events have been recorded" />
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Audit events"
                :columns="columns"
                :rows="events.rows"
                :row-key="(row) => `${row.source}-${row.id}`"
            >
                <template #cell-event="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/audit/${row.source}/${row.id}`">{{
                        auditEventLabel(row.event)
                    }}</Link
                    ><span class="rec-row-sub">{{ auditSources[row.source] ?? row.source }}</span></template
                >
                <template #cell-actor="{ row }"
                    >{{ row.actor
                    }}<span v-if="row.actor_email" class="rec-row-sub adm-phone-hidden">{{
                        row.actor_email
                    }}</span></template
                >
                <template #cell-target="{ row }"
                    ><span class="rec-ellipsis" :title="row.target">{{ target(row) }}</span
                    ><span v-if="row.workspace" class="rec-row-sub">{{ row.workspace }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge
                        v-if="row.outcome"
                        :label="auditOutcomes[row.outcome]?.label ?? row.outcome"
                        :tone="auditOutcomes[row.outcome]?.tone ?? 'neutral'"
                    /><span v-else class="rec-fine">Not recorded</span></template
                >
                <template #cell-occurred_at="{ row }"
                    ><time :datetime="row.occurred_at" :title="dateTime(row.occurred_at)">{{
                        recentTime(row.occurred_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="events.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Audit pages"
                    :page="page"
                    :per-page="25"
                    :count="events.rows.length"
                    :total="events.total"
                    noun="events"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
