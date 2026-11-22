<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import BackLink from '../features/admin/BackLink.vue';
import { dateTime, dispositions, titleCase } from '../features/admin/format';

type Item = {
    type: string;
    reference: string;
    name: string;
    evidence: string;
    disposition: string;
    reasons: string[];
    records: Record<string, number>;
};
const props = defineProps<{
    run: {
        id: string;
        batch_id: string;
        batch: string;
        created_at: string;
        created_by: string;
        eligible: number;
        blocked: number;
        protected: number;
        stale: boolean;
        items: Item[];
    };
}>();
// Chips put blocked records first, then protected ones; eligible records last.
const order = ['blocked', 'protected', 'eligible'];
const workspaces = computed(() => props.run.items.filter((item) => item.type === 'workspace').length);
const accounts = computed(() => props.run.items.length - workspaces.value);
const view = ref('');
const chips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.run.items.length },
    ...order.map((value) => ({
        value,
        label: dispositions[value]!.label,
        count: props.run.items.filter((item) => item.disposition === value).length,
    })),
]);
const items = computed(() =>
    view.value ? props.run.items.filter((item) => item.disposition === view.value) : props.run.items,
);
const columns = [
    { key: 'name', label: 'Record' },
    { key: 'disposition', label: 'Status' },
    { key: 'type', label: 'Type' },
    { key: 'reasons', label: 'Block or protection reason' },
    { key: 'records', label: 'Related records' },
];
const describe = (records: Record<string, number>) =>
    Object.entries(records)
        .filter(([, total]) => total > 0)
        .map(([table, total]) => `${titleCase(table)} ${total}`)
        .join(' · ') || 'None';
</script>

<template>
    <AdminShell :title="`Dry run of ${run.batch}`" compact>
        <template #back><BackLink :href="`/admin/cleanup/${run.batch_id}`" :label="`Back to ${run.batch}`" /></template>
        <template #description>Recorded {{ dateTime(run.created_at) }} by {{ run.created_by }}</template>
        <template #actions><StatusBadge label="Preview — no data deleted" tone="warning" /></template>
        <p v-if="run.stale" class="wd-warning rec-banner" role="status">
            This dry run is out of date: the batch's marks changed after it was recorded.
            <Link :href="`/admin/cleanup/${run.batch_id}`" class="wd-link">Run a new dry run</Link>
        </p>
        <ul class="rec-stats" aria-label="Dry run summary">
            <li>
                <span>Eligible</span><strong>{{ run.eligible }}</strong
                ><small>Marked, with nothing blocking removal</small>
            </li>
            <li :class="{ 'is-alert': run.blocked }">
                <span>Blocked</span><strong>{{ run.blocked }}</strong
                ><small>A dependency or retention rule prevents removal</small>
            </li>
            <li>
                <span>Protected</span><strong>{{ run.protected }}</strong
                ><small>Never removed by cleanup</small>
            </li>
            <li>
                <span>Marked records</span><strong>{{ run.items.length }}</strong
                ><small
                    >{{ workspaces }} {{ workspaces === 1 ? 'workspace' : 'workspaces' }} · {{ accounts }}
                    {{ accounts === 1 ? 'account' : 'accounts' }}</small
                >
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="manifest-heading">
            <header class="rec-card-head">
                <h2 id="manifest-heading">Manifest</h2>
                <span class="rec-count">{{ run.items.length.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Counts only · record contents, file names and tokens are not listed</p>
            </header>
            <div v-if="run.items.length" class="rec-chips">
                <FilterChips label="Show disposition" compact :chips="chips" :current="view" @select="view = $event" />
            </div>
            <EmptyState v-if="!items.length" title="No records have this disposition" />
            <DataTable
                v-else
                class="rec-table"
                caption="Cleanup manifest"
                :columns="columns"
                :rows="items"
                :row-key="(row) => `${row.type}-${row.reference}`"
            >
                <template #cell-name="{ row }"
                    ><span class="rec-row-name">{{ row.name }}</span
                    ><span class="rec-row-sub">{{ row.reference }}</span
                    ><span class="rec-row-sub" :title="row.evidence">Evidence: {{ row.evidence }}</span></template
                >
                <template #cell-disposition="{ row }"
                    ><StatusBadge
                        :label="dispositions[row.disposition]?.label ?? row.disposition"
                        :tone="dispositions[row.disposition]?.tone ?? 'neutral'"
                /></template>
                <template #cell-type="{ row }">{{ titleCase(row.type) }}</template>
                <template #cell-reasons="{ row }"
                    ><ul v-if="row.reasons.length">
                        <li v-for="reason in row.reasons" :key="reason">{{ reason }}</li>
                    </ul>
                    <template v-else>None</template></template
                >
                <template #cell-records="{ row }">{{ describe(row.records) }}</template>
            </DataTable>
            <p class="rec-fine">Audit history is kept in every case and is not part of any manifest.</p>
        </section>
        <section class="rec-card adm-panel" aria-labelledby="execution-heading">
            <header class="rec-card-head"><h2 id="execution-heading">Execution</h2></header>
            <UnavailableNotice
                title="This cleanup cannot be executed from the console"
                reason="Deletion is a separately reviewed step outside this console. Use this manifest's reference in that review. Nothing was changed by this dry run."
            />
            <p class="adm-panel-body">Reference: <CopyReference :value="run.id" label="dry run reference" /></p>
        </section>
    </AdminShell>
</template>
