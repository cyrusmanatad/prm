<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { age, dateTime, recentTime } from '../features/admin/format';

type Failed = {
    reference: string;
    queue: string;
    connection: string;
    failed_at: string;
    job: string | null;
    category: string | null;
};
type Tone = 'neutral' | 'success' | 'warning' | 'danger';
type Service = {
    key: string;
    service: string;
    status: string;
    tone: Tone;
    summary: string;
    observed: string | null;
    missing?: string;
};
const props = defineProps<{
    observed_at: string;
    scheduler: { observed_at: string; age_seconds: number } | null;
    outbox: { pending: number; oldest_pending_at: string | null; uncertain_unresolved: number };
    failed: { total: number; last_24_hours: number; rows: Failed[] };
    queue: { name: string; backend: string; reachable: boolean; pending: number | null };
}>();
const short = (name: string | null) => name?.split('\\').pop() ?? null;
const services = computed<Service[]>(() => {
    const scheduler = props.scheduler;
    const late = !!scheduler && scheduler.age_seconds > 180;
    return [
        {
            key: 'database',
            service: 'Database',
            status: 'Healthy',
            tone: 'success',
            summary: 'Answered this request.',
            observed: props.observed_at,
        },
        {
            key: 'redis',
            service: 'Queue store (Redis)',
            status: props.queue.reachable ? 'Healthy' : 'Down',
            tone: props.queue.reachable ? 'success' : 'danger',
            summary: props.queue.reachable
                ? 'Answered a ping from the web application.'
                : 'Did not answer the web application. Queue depth is unknown.',
            observed: props.observed_at,
        },
        {
            key: 'scheduler',
            service: 'Scheduler',
            status: !scheduler ? 'Unknown' : late ? 'Degraded' : 'Healthy',
            tone: !scheduler ? 'neutral' : late ? 'danger' : 'success',
            summary: !scheduler
                ? 'No heartbeat has been recorded. Confirm the scheduler service is running.'
                : `Last heartbeat ${age(scheduler.age_seconds)} ago; one is expected every minute.`,
            observed: scheduler?.observed_at ?? null,
            missing: 'Never observed',
        },
        {
            key: 'worker',
            service: 'Queue worker',
            status: 'Unknown',
            tone: 'neutral',
            summary: 'Worker heartbeats are not collected. Use queue depth and failed jobs as indirect signals.',
            observed: null,
        },
        {
            key: 'scanner',
            service: 'Upload scanner',
            status: 'Unknown',
            tone: 'neutral',
            summary: 'Scanner health is not collected in this view.',
            observed: null,
        },
        {
            key: 'mail',
            service: 'Mail provider',
            status: 'Not configured',
            tone: 'neutral',
            summary: 'Provider health and delivery receipts are not monitored.',
            observed: null,
        },
    ];
});
const failing = computed(() => services.value.filter((row) => row.tone === 'danger').length);
const unobserved = computed(() => services.value.filter((row) => row.tone === 'neutral').length);
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
    { key: 'failed_at', label: 'Failed' },
];
const refreshing = ref(false);
function refresh() {
    if (refreshing.value) return;
    router.reload({ onStart: () => (refreshing.value = true), onFinish: () => (refreshing.value = false) });
}
</script>

<template>
    <AdminShell title="Jobs and health" compact>
        <template #description
            >Observed {{ dateTime(observed_at) }} · read-only: nothing can be retried, flushed or restarted
            here</template
        >
        <template #actions>
            <Button type="button" class="wd-button wd-button-secondary" :disabled="refreshing" @click="refresh"
                >Refresh observations</Button
            >
        </template>
        <ul class="rec-stats" aria-label="Health summary">
            <li :class="{ 'is-alert': failing }">
                <span>Down or degraded</span><strong>{{ failing }}</strong
                ><small>{{ unobserved }} of {{ services.length }} services not observed</small>
            </li>
            <li :class="{ 'is-alert': !queue.reachable }">
                <span>Background queue</span><strong>{{ queue.pending ?? 'Unknown' }}</strong>
                <small v-if="queue.reachable">Pending in {{ queue.backend }} “{{ queue.name }}”</small>
                <small v-else><b>Redis did not answer</b></small>
            </li>
            <li :class="{ 'is-alert': outbox.uncertain_unresolved }">
                <component
                    :is="outbox.uncertain_unresolved ? Link : 'div'"
                    v-bind="outbox.uncertain_unresolved ? { href: '/admin/notifications?state=uncertain' } : {}"
                >
                    <span>Notification outbox</span><strong>{{ outbox.pending }}</strong>
                    <small v-if="outbox.uncertain_unresolved"
                        ><b
                            >{{ outbox.uncertain_unresolved }} uncertain
                            {{ outbox.uncertain_unresolved === 1 ? 'send' : 'sends' }} to review</b
                        ></small
                    >
                    <small v-else-if="outbox.oldest_pending_at"
                        >Oldest queued {{ recentTime(outbox.oldest_pending_at) }}</small
                    >
                    <small v-else>Nothing queued</small>
                </component>
            </li>
            <li :class="{ 'is-alert': failed.last_24_hours }">
                <span>Failed in 24 hours</span><strong>{{ failed.last_24_hours }}</strong
                ><small>{{ failed.total }} retained in total</small>
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="services-heading">
            <header class="rec-card-head">
                <h2 id="services-heading">Services</h2>
                <span class="rec-count">{{ services.length }}</span>
                <p class="rec-note">A missing observation is Unknown, never healthy</p>
            </header>
            <DataTable
                class="rec-table"
                caption="Services"
                :columns="serviceColumns"
                :rows="services"
                :row-key="(row) => row.key"
            >
                <template #cell-service="{ row }"
                    ><span class="rec-row-name">{{ row.service }}</span></template
                >
                <template #cell-status="{ row }"><StatusBadge :label="row.status" :tone="row.tone" /></template>
                <template #cell-summary="{ row }">{{ row.summary }}</template>
                <template #cell-observed="{ row }"
                    ><time v-if="row.observed" :datetime="row.observed" :title="dateTime(row.observed)">{{
                        recentTime(row.observed)
                    }}</time
                    ><span v-else>{{ row.missing ?? 'Not collected' }}</span></template
                >
            </DataTable>
        </section>
        <section class="rec-card" aria-labelledby="failed-heading">
            <header class="rec-card-head">
                <h2 id="failed-heading">Failed jobs</h2>
                <span class="rec-count">{{ failed.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">
                    <template v-if="failed.rows.length">Latest {{ failed.rows.length }} · </template>Payloads and error
                    messages are withheld because they can contain private data
                </p>
            </header>
            <EmptyState
                v-if="!failed.rows.length"
                title="No failed jobs are retained"
                description="Failures recorded by the queue worker appear here."
            />
            <DataTable
                v-else
                class="rec-table adm-no-status"
                caption="Failed jobs"
                :columns="failedColumns"
                :rows="failed.rows"
                :row-key="(row) => row.reference"
            >
                <template #cell-job="{ row }"
                    ><span class="rec-row-name">{{ short(row.job) ?? 'Unrecognised job type' }}</span
                    ><span class="rec-row-sub">{{ row.reference }}</span></template
                >
                <template #cell-category="{ row }">{{ short(row.category) ?? 'Not classified' }}</template>
                <template #cell-failed_at="{ row }"
                    ><time :datetime="row.failed_at" :title="dateTime(row.failed_at)">{{
                        recentTime(row.failed_at)
                    }}</time></template
                >
            </DataTable>
        </section>
    </AdminShell>
</template>
