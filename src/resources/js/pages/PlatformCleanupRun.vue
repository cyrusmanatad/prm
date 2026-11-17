<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import StatCard from '../components/StatCard.vue';
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
defineProps<{
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
const columns = [
    { key: 'name', label: 'Record' },
    { key: 'type', label: 'Type' },
    { key: 'disposition', label: 'Proposed disposition' },
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
    <AdminShell
        :title="`Dry run of ${run.batch}`"
        :description="`Recorded ${dateTime(run.created_at)} by ${run.created_by}`"
        eyebrow="Cleanup dry run"
    >
        <template #back><BackLink :href="`/admin/cleanup/${run.batch_id}`" :label="`Back to ${run.batch}`" /></template>
        <template #actions><StatusBadge label="Preview — no data deleted" tone="warning" /></template>
        <p v-if="run.stale" class="wd-warning" role="status">
            This dry run is out of date: the batch's marks changed after it was recorded.
            <Link :href="`/admin/cleanup/${run.batch_id}`" class="wd-link">Run a new dry run</Link>
        </p>
        <section aria-labelledby="run-counts">
            <h2 id="run-counts" class="wd-sr-only">Summary</h2>
            <div class="wd-stat-grid">
                <StatCard label="Eligible" :value="run.eligible" scope="Marked records with nothing blocking them" />
                <StatCard
                    label="Blocked"
                    :value="run.blocked"
                    scope="A dependency or retention rule prevents removal"
                />
                <StatCard label="Protected" :value="run.protected" scope="Never removed by cleanup" />
            </div>
        </section>
        <ContentSection
            title="Manifest"
            description="Counts only. Record contents, file names and tokens are not listed."
        >
            <p>Reference: <CopyReference :value="run.id" label="dry run reference" /></p>
            <DataTable
                caption="Cleanup manifest"
                :columns="columns"
                :rows="run.items"
                :row-key="(row) => `${row.type}-${row.reference}`"
            >
                <template #cell-name="{ row }"
                    >{{ row.name }}<span class="wd-data-secondary">{{ row.reference }}</span
                    ><span class="wd-data-secondary">Evidence: {{ row.evidence }}</span></template
                >
                <template #cell-type="{ row }">{{ titleCase(row.type) }}</template>
                <template #cell-disposition="{ row }"
                    ><StatusBadge
                        :label="dispositions[row.disposition]?.label ?? row.disposition"
                        :tone="dispositions[row.disposition]?.tone ?? 'neutral'"
                /></template>
                <template #cell-reasons="{ row }"
                    ><ul v-if="row.reasons.length">
                        <li v-for="reason in row.reasons" :key="reason">{{ reason }}</li>
                    </ul>
                    <span v-else class="wd-data-secondary">None</span></template
                >
                <template #cell-records="{ row }">{{ describe(row.records) }}</template>
            </DataTable>
            <p class="wd-hint">Audit history is kept in every case and is not part of any manifest.</p>
        </ContentSection>
        <ContentSection title="Execution">
            <UnavailableNotice
                title="This cleanup cannot be executed from the console"
                reason="Deletion is a separately reviewed step outside this console. Use this manifest's reference in that review. Nothing was changed by this dry run."
            />
        </ContentSection>
    </AdminShell>
</template>
