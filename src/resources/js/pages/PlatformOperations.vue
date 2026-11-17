<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { age, dateTime } from '../features/admin/format';

type Failed = {
    reference: string;
    queue: string;
    connection: string;
    failed_at: string;
    job: string | null;
    category: string | null;
};
type Tone = 'neutral' | 'success' | 'warning' | 'danger';
const props = defineProps<{
    observed_at: string;
    scheduler: { observed_at: string; age_seconds: number } | null;
    outbox: { pending: number; oldest_pending_at: string | null; uncertain_unresolved: number };
    failed: { total: number; last_24_hours: number; rows: Failed[] };
    queue: { name: string; backend: string; reachable: boolean; pending: number | null };
}>();
const short = (name: string | null) => name?.split('\\').pop() ?? null;
const services = computed(() => {
    const scheduler = props.scheduler;
    const late = !!scheduler && scheduler.age_seconds > 180;
    const rows: { key: string; service: string; status: string; tone: Tone; summary: string; observed: string }[] = [
        {
            key: 'database',
            service: 'Database',
            status: 'Healthy',
            tone: 'success',
            summary: 'Answered this request.',
            observed: dateTime(props.observed_at),
        },
        {
            key: 'redis',
            service: 'Queue store (Redis)',
            status: props.queue.reachable ? 'Healthy' : 'Down',
            tone: props.queue.reachable ? 'success' : 'danger',
            summary: props.queue.reachable
                ? 'Answered a ping from the web application.'
                : 'Did not answer the web application. Queue depth is unknown.',
            observed: dateTime(props.observed_at),
        },
        {
            key: 'scheduler',
            service: 'Scheduler',
            status: !scheduler ? 'Unknown' : late ? 'Degraded' : 'Healthy',
            tone: !scheduler ? 'neutral' : late ? 'danger' : 'success',
            summary: !scheduler
                ? 'No heartbeat has been recorded. Confirm the scheduler service is running.'
                : `Last heartbeat ${age(scheduler.age_seconds)} ago; one is expected every minute.`,
            observed: scheduler ? dateTime(scheduler.observed_at) : 'Never observed',
        },
        {
            key: 'worker',
            service: 'Queue worker',
            status: 'Unknown',
            tone: 'neutral',
            summary: 'Worker heartbeats are not collected. Use queue depth and failed jobs as indirect signals.',
            observed: 'Not collected',
        },
        {
            key: 'scanner',
            service: 'Upload scanner',
            status: 'Unknown',
            tone: 'neutral',
            summary: 'Scanner health is not collected in this view.',
            observed: 'Not collected',
        },
        {
            key: 'mail',
            service: 'Mail provider',
            status: 'Not configured',
            tone: 'neutral',
            summary: 'Provider health and delivery receipts are not monitored.',
            observed: 'Not collected',
        },
    ];
    return rows;
});
const serviceColumns = [
    { key: 'service', label: 'Service' },
    { key: 'status', label: 'Status' },
    { key: 'summary', label: 'Summary' },
    { key: 'observed', label: 'Last observed' },
];
const failedColumns = [
    { key: 'job', label: 'Job' },
    { key: 'queue', label: 'Queue' },
    { key: 'category', label: 'Error category' },
    { key: 'failed_at', label: 'Failed at' },
];
const queueRows = computed(() => [
    {
        key: 'redis',
        name: props.queue.name,
        backend: props.queue.backend,
        pending: props.queue.pending === null ? 'Unknown' : String(props.queue.pending),
        note: 'Live background-job queue.',
    },
    {
        key: 'outbox',
        name: 'Notification outbox',
        backend: 'PostgreSQL',
        pending: String(props.outbox.pending),
        note: props.outbox.oldest_pending_at
            ? `Oldest queued ${dateTime(props.outbox.oldest_pending_at)}.`
            : 'Nothing queued.',
    },
]);
const queueColumns = [
    { key: 'name', label: 'Queue' },
    { key: 'backend', label: 'Backend' },
    { key: 'pending', label: 'Pending', numeric: true },
    { key: 'note', label: 'Detail' },
];
function refresh() {
    router.reload();
}
</script>

<template>
    <AdminShell
        title="Jobs and health"
        description="Read-only observations. A missing observation is shown as Unknown, never as healthy or zero. Nothing can be retried, flushed or restarted from here."
        eyebrow="Operations"
    >
        <template #actions>
            <Button type="button" class="wd-button wd-button-secondary" @click="refresh">Refresh observations</Button>
        </template>
        <ContentSection title="Services" :description="`Observed ${dateTime(observed_at)}.`">
            <DataTable caption="Services" :columns="serviceColumns" :rows="services" :row-key="(row) => row.key">
                <template #cell-status="{ row }"><StatusBadge :label="row.status" :tone="row.tone" /></template>
            </DataTable>
        </ContentSection>
        <ContentSection title="Queues">
            <DataTable caption="Queues" :columns="queueColumns" :rows="queueRows" :row-key="(row) => row.key" />
            <p v-if="outbox.uncertain_unresolved">
                {{ outbox.uncertain_unresolved }} uncertain
                {{ outbox.uncertain_unresolved === 1 ? 'send is' : 'sends are' }} waiting for review.
                <Link href="/admin/notifications?state=uncertain" class="wd-link">Review uncertain sends</Link>
            </p>
        </ContentSection>
        <ContentSection
            title="Failed jobs"
            :description="`${failed.last_24_hours} in the last 24 hours, ${failed.total} retained in total. Showing the latest ${failed.rows.length}. Payloads and error messages are withheld because they can contain private data.`"
        >
            <EmptyState
                v-if="!failed.rows.length"
                title="No failed jobs are retained"
                description="Failures recorded by the queue worker appear here."
            />
            <DataTable
                v-else
                caption="Failed jobs"
                :columns="failedColumns"
                :rows="failed.rows"
                :row-key="(row) => row.reference"
            >
                <template #cell-job="{ row }"
                    >{{ short(row.job) ?? 'Unrecognised job type'
                    }}<span class="wd-data-secondary">{{ row.reference }}</span></template
                >
                <template #cell-category="{ row }">{{ short(row.category) ?? 'Not classified' }}</template>
                <template #cell-failed_at="{ row }">{{ dateTime(row.failed_at) }}</template>
            </DataTable>
        </ContentSection>
    </AdminShell>
</template>
