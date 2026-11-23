<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import DataTable from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import Pagination from '../../components/Pagination.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import AdminCard from './AdminCard.vue';
import { dateTime, recentTime } from './format';

export type ReconciliationKind = 'orphaned' | 'missing' | 'held' | 'unrecognised';
export type Reconciliation = {
    last_run: {
        id: string;
        started_at: string;
        status: 'completed' | 'failed';
        listed: number;
        referenced: number;
        skipped_recent: number;
        failure: string | null;
    } | null;
    last_completed: string | null;
    /** Findings matching the current filter, across all pages. */
    total: number;
    /** Counts per kind; they never follow the filter. */
    counts: Record<'all' | ReconciliationKind | 'eligible', number>;
    findings: {
        kind: ReconciliationKind;
        org_id: string | null;
        workspace: string | null;
        document_id: string | null;
        size: number | null;
        first_seen_at: string;
        last_seen_at: string;
        observations: number;
        eligible: boolean;
    }[];
};

/**
 * PRIV-05 report (P4): stored onboarding uploads compared with their records. Read-only: nothing here deletes a file.
 * Filtered and paged on the server, 25 at a time; the page's other filters stay in the address.
 */
const props = defineProps<{ reconciliation: Reconciliation; filter: string; page: number }>();
const url = usePage().url;
function href(page: number, finding = props.filter): string {
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    params.delete('finding');
    params.delete('finding_page');
    if (finding) params.set('finding', finding);
    if (page > 1) params.set('finding_page', String(page));
    const query = params.toString();
    return `/admin/cleanup${query ? `?${query}` : ''}`;
}
const kinds: Record<ReconciliationKind, { label: string; meaning: string; tone: 'neutral' | 'warning' | 'danger' }> = {
    orphaned: { label: 'File without a record', meaning: 'Stored, but no document refers to it', tone: 'warning' },
    missing: {
        label: 'Record without a file',
        meaning: 'A document refers to a file that is not stored',
        tone: 'danger',
    },
    held: {
        label: 'Held for review',
        meaning: 'Pending or rejected on a request that can still be edited',
        tone: 'neutral',
    },
    unrecognised: {
        label: 'Unrecognised file',
        meaning: 'Stored under a key this application does not write',
        tone: 'warning',
    },
};
/** Waiting period before a file without a record can become eligible (P4). */
const GRACE_DAYS = 7;
const DAY = 86_400_000;
type Tile = { key: string; label: string; note: string; count: number; tone?: 'danger' };
// Counts double as filters: each tile opens its findings, and the selected tile toggles back to all of them.
const tiles = computed<Tile[]>(() => {
    const c = props.reconciliation.counts;
    return [
        {
            key: 'orphaned',
            label: 'Files without a record',
            note: `Eligible after ${GRACE_DAYS} days`,
            count: c.orphaned,
        },
        {
            key: 'missing',
            label: 'Records without a file',
            note: 'Never deleted',
            count: c.missing,
            tone: c.missing ? 'danger' : undefined,
        },
        { key: 'held', label: 'Held for review', note: 'Pending or rejected scans', count: c.held },
        ...(c.unrecognised
            ? [{ key: 'unrecognised', label: 'Unrecognised files', note: 'Never eligible', count: c.unrecognised }]
            : []),
        { key: 'eligible', label: 'Eligible for deletion', note: 'Needs approved authority', count: c.eligible },
    ];
});
const filterLabel = computed(() => tiles.value.find((tile) => tile.key === props.filter)?.label.toLowerCase());
/** The card's verdict: has anything been compared, did it work, and does anything need a look. */
const verdict = computed(() => {
    const run = props.reconciliation.last_run;
    if (!run) return { label: 'Not run yet', tone: 'neutral' as const };
    if (run.status === 'failed') return { label: 'Last run failed', tone: 'danger' as const };
    return props.reconciliation.counts.all
        ? { label: 'Needs review', tone: 'warning' as const }
        : { label: 'All matched', tone: 'success' as const };
});
const rows = computed(() => props.reconciliation.findings);
const size = (bytes: number | null) =>
    bytes === null ? 'Not stored' : bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(bytes < 10240 ? 1 : 0)} KB`;
/** Only files without a record move towards eligibility, so only they carry a status. */
function status(row: Reconciliation['findings'][number]): { label: string; tone: 'warning' | 'neutral' } | null {
    if (row.kind !== 'orphaned') return null;
    if (row.eligible) return { label: 'Eligible', tone: 'warning' };
    const left = Math.ceil(GRACE_DAYS - (Date.now() - new Date(row.first_seen_at).getTime()) / DAY);
    return {
        label: left > 0 ? `Waiting · ${left} ${left === 1 ? 'day' : 'days'} left` : 'Waiting · next check',
        tone: 'neutral',
    };
}
const columns = [
    { key: 'kind', label: 'Finding' },
    { key: 'status', label: 'Status' },
    { key: 'workspace', label: 'Workspace' },
    { key: 'size', label: 'Size', numeric: true },
    { key: 'first_seen_at', label: 'First seen' },
    { key: 'observations', label: 'Checks', numeric: true },
];
</script>

<template>
    <AdminCard title="Storage reconciliation">
        <template #badge><StatusBadge :label="verdict.label" :tone="verdict.tone" /></template>
        <p class="adm-recon-meta">
            <strong>Report only</strong>
            <template v-if="reconciliation.last_run"
                ><span aria-hidden="true">·</span
                ><span
                    >Checked
                    <time
                        :datetime="reconciliation.last_run.started_at"
                        :title="dateTime(reconciliation.last_run.started_at)"
                        >{{ recentTime(reconciliation.last_run.started_at) }}</time
                    ></span
                ><span aria-hidden="true">·</span
                ><span>{{ reconciliation.last_run.listed }} files compared</span></template
            >
            <span aria-hidden="true">·</span><span>Runs daily at 03:15 UTC</span>
        </p>
        <p
            v-if="reconciliation.last_run?.status === 'failed'"
            class="wd-warning rec-banner adm-panel-body"
            role="status"
        >
            The last comparison could not finish: {{ reconciliation.last_run.failure ?? 'storage was unavailable' }}.
            Earlier findings are unchanged; the next run will retry.
        </p>
        <p v-if="!reconciliation.last_run" class="rec-fine">
            No comparison has run yet. The first daily run records what it finds here.
        </p>
        <template v-else>
            <ul class="adm-recon-tiles" aria-label="Findings by kind">
                <li v-for="tile in tiles" :key="tile.key">
                    <Link
                        :href="href(1, filter === tile.key ? '' : tile.key)"
                        :class="{ 'is-danger': tile.tone === 'danger' }"
                        v-bind="{ 'aria-current': filter === tile.key ? 'true' : undefined }"
                        preserve-scroll
                    >
                        <span>{{ tile.label }}</span>
                        <strong>{{ tile.count.toLocaleString('en-GB') }}</strong>
                        <small>{{ tile.note }}</small>
                    </Link>
                </li>
            </ul>
            <div v-if="filter" class="adm-recon-filter" role="status">
                <span>Showing {{ reconciliation.total.toLocaleString('en-GB') }} {{ filterLabel }}</span>
                <Link :href="href(1, '')" class="wd-link" preserve-scroll
                    >Show all {{ reconciliation.counts.all.toLocaleString('en-GB') }}</Link
                >
            </div>
            <EmptyState
                v-if="!reconciliation.counts.all"
                title="Every stored upload matches a record"
                description="Files without a record, records without a file and uploads held for review appear here."
            />
            <EmptyState v-else-if="!rows.length" title="No findings of this kind" />
            <DataTable
                v-else
                class="rec-table"
                caption="Storage reconciliation findings"
                :columns="columns"
                :rows="rows"
                :row-key="(row) => `${row.kind}-${row.document_id ?? row.first_seen_at}-${row.size}`"
            >
                <template #cell-kind="{ row }"
                    ><span class="rec-row-name">{{ kinds[row.kind].label }}</span
                    ><span class="rec-row-sub" :title="kinds[row.kind].meaning">{{
                        row.document_id ? `Document ${row.document_id.slice(0, 8)}` : kinds[row.kind].meaning
                    }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge v-if="status(row)" :label="status(row)!.label" :tone="status(row)!.tone"
                /></template>
                <template #cell-workspace="{ row }"
                    ><Link v-if="row.org_id && row.workspace" :href="`/admin/workspaces/${row.org_id}`">{{
                        row.workspace
                    }}</Link
                    ><template v-else>{{
                        row.org_id ? 'Workspace no longer exists' : 'Not attributed'
                    }}</template></template
                >
                <template #cell-size="{ row }">{{ size(row.size) }}</template>
                <template #cell-first_seen_at="{ row }"
                    ><time :datetime="row.first_seen_at" :title="dateTime(row.first_seen_at)">{{
                        recentTime(row.first_seen_at)
                    }}</time></template
                >
            </DataTable>
            <div v-if="rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Finding pages"
                    :page="page"
                    :per-page="25"
                    :count="rows.length"
                    :total="reconciliation.total"
                    noun="findings"
                    numbered
                    :href-for="(target) => href(target)"
                />
            </div>
            <p class="adm-recon-hint">
                <Info :size="16" aria-hidden="true" />
                <span
                    >A file without a record becomes eligible after two matching daily checks and {{ GRACE_DAYS }} days.
                    Nothing is deleted from here.</span
                >
            </p>
        </template>
    </AdminCard>
</template>
