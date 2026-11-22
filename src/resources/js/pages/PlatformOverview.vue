<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import { ArrowRight } from '@lucide/vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { age, auditEventLabel, auditTargetLabel, dateTime, recentTime } from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';

type Activity = {
    id: string;
    occurred_at: string;
    event: string;
    target: string;
    actor: string;
    target_kind?: string | null;
    target_name?: string | null;
};
const props = defineProps<{
    overview: {
        observed_at: string;
        accounts: number | null;
        creator_workspaces: number | null;
        brand_workspaces: number | null;
        active_assignments: number | null;
        expired_assignments: number | null;
        pending_notices: number | null;
        uncertain_sends: number | null;
        failed_jobs: number | null;
        scheduler_age_seconds: number | null;
        scheduler_visible: boolean;
        operators_without_mfa: number | null;
        long_suspensions: number | null;
        support_awaiting: number | null;
        support_expiring: number | null;
        cleanup_blocked: number | null;
        brand_requests_pending?: number | null;
        brands_unrecorded?: number | null;
        activity: Activity[] | null;
    };
}>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const can = (capability: string) => allowed(context.value, capability);
type Attention = {
    key: string;
    tone: 'warning' | 'danger';
    title: string;
    next: string;
    href: string;
    /** Full link text, used as the accessible name; `verb` is the short visible label. */
    link: string;
    verb?: string;
};
const attention = computed(() => {
    const o = props.overview;
    const items: Attention[] = [];
    if (o.uncertain_sends)
        items.push({
            key: 'uncertain',
            tone: 'warning',
            title: `${o.uncertain_sends} uncertain ${o.uncertain_sends === 1 ? 'send needs' : 'sends need'} review`,
            next: 'Check provider evidence and record a resolution. Nothing is resent automatically.',
            href: '/admin/notifications?state=uncertain',
            link: 'Review uncertain sends',
        });
    if (o.scheduler_visible && o.scheduler_age_seconds === null)
        items.push({
            key: 'scheduler-missing',
            tone: 'warning',
            title: 'No scheduler heartbeat has been observed',
            next: 'Scheduler health is unknown. Confirm the scheduler service is running.',
            href: '/admin/operations',
            link: 'Open jobs and health',
        });
    else if (o.scheduler_age_seconds !== null && o.scheduler_age_seconds > 180)
        items.push({
            key: 'scheduler-late',
            tone: 'danger',
            title: `Scheduler heartbeat is ${age(o.scheduler_age_seconds)} old`,
            next: 'A heartbeat is expected every minute. Reminders and digests may be delayed.',
            href: '/admin/operations',
            link: 'Open jobs and health',
        });
    if (o.failed_jobs)
        items.push({
            key: 'failed',
            tone: 'danger',
            title: `${o.failed_jobs} background ${o.failed_jobs === 1 ? 'job' : 'jobs'} failed in the last 24 hours`,
            next: 'Inspect the sanitized failure category. Jobs are not retried from this console.',
            href: '/admin/operations',
            link: 'Inspect failed jobs',
        });
    if (o.expired_assignments)
        items.push({
            key: 'expired',
            tone: 'warning',
            title: `${o.expired_assignments} ${o.expired_assignments === 1 ? 'workspace has' : 'workspaces have'} an expired assignment`,
            next: 'Creates are blocked until a replacement assignment is recorded. Existing records are kept.',
            href: '/admin/workspaces?state=expired',
            link: 'View expired workspaces',
        });
    if (o.operators_without_mfa)
        items.push({
            key: 'mfa',
            tone: 'warning',
            title: `${o.operators_without_mfa} ${o.operators_without_mfa === 1 ? 'operator has' : 'operators have'} not enrolled an authenticator`,
            next: 'Their grant cannot be used until they enroll at My admin security.',
            href: '/admin/operators',
            link: 'View platform access',
        });
    if (o.long_suspensions)
        items.push({
            key: 'suspensions',
            tone: 'warning',
            title: `${o.long_suspensions} ${o.long_suspensions === 1 ? 'suspension has' : 'suspensions have'} lasted more than 30 days`,
            next: 'Review whether each should continue. Suspension has no automatic end.',
            href: '/admin/accounts?access=suspended',
            link: 'View suspended accounts',
        });
    if (o.brand_requests_pending)
        items.push({
            key: 'brand-requests',
            tone: 'warning',
            title: `${o.brand_requests_pending} Brand ${o.brand_requests_pending === 1 ? 'request is' : 'requests are'} awaiting a decision`,
            next: 'No Brand workspace is created until a request is approved. Approve, ask for more information or decline.',
            href: '/admin/brand-requests?status=pending',
            link: 'Review Brand requests',
        });
    if (o.brands_unrecorded)
        items.push({
            key: 'brands-unrecorded',
            tone: 'warning',
            title: `${o.brands_unrecorded} Brand ${o.brands_unrecorded === 1 ? 'workspace has' : 'workspaces have'} no recorded approval`,
            next: 'These predate review and keep their access. The offline rollout command records their approval.',
            href: '/admin/brand-requests?source=rollout',
            link: 'View recorded rollout approvals',
        });
    if (o.support_awaiting)
        items.push({
            key: 'support',
            tone: 'warning',
            title: `${o.support_awaiting} support ${o.support_awaiting === 1 ? 'request is' : 'requests are'} waiting for a workspace owner`,
            next: 'Only a workspace owner can approve. Withdraw requests that are no longer needed.',
            href: '/admin/support-access?state=requested',
            link: 'View support requests',
        });
    if (o.support_expiring)
        items.push({
            key: 'support-expiring',
            tone: 'warning',
            title: `${o.support_expiring} support ${o.support_expiring === 1 ? 'grant ends' : 'grants end'} within two hours`,
            next: 'Access stops at the approved time. It cannot be extended; a new request needs a new owner approval.',
            href: '/admin/support-access?state=approved',
            link: 'View active support access',
        });
    if (o.cleanup_blocked)
        items.push({
            key: 'cleanup-blocked',
            tone: 'warning',
            title: `${o.cleanup_blocked} cleanup ${o.cleanup_blocked === 1 ? 'batch has' : 'batches have'} blocked records in the latest dry run`,
            next: 'Open the dry run to see why each record is blocked. Dry runs delete nothing.',
            href: '/admin/cleanup?view=blocked',
            link: 'Review cleanup batches',
        });
    // Failures first, then reviews, keeping the order within each group.
    return items
        .map((item) => ({ ...item, verb: item.verb ?? item.link.split(' ')[0] }))
        .sort((a, b) => Number(b.tone === 'danger') - Number(a.tone === 'danger'));
});
type Tile = {
    key: string;
    label: string;
    value: string | null;
    note: string;
    alert?: string;
    href?: string;
    danger?: boolean;
};
const count = (value: number | null | undefined) =>
    value === null || value === undefined ? null : value.toLocaleString('en-GB');
const tiles = computed<Tile[]>(() => {
    const o = props.overview;
    const link = (capability: string, href: string) => (can(capability) ? href : undefined);
    return [
        {
            key: 'accounts',
            label: 'Accounts',
            value: count(o.accounts),
            note: 'All registered identities',
            href: link('directory.read', '/admin/accounts'),
        },
        {
            key: 'creator',
            label: 'Creator workspaces',
            value: count(o.creator_workspaces),
            note: 'All creator workspaces',
            href: link('directory.read', '/admin/workspaces?kind=creator'),
        },
        {
            key: 'brand',
            label: 'Brand workspaces',
            value: count(o.brand_workspaces),
            note: 'All Brand workspaces',
            href: link('directory.read', '/admin/workspaces?kind=brand'),
        },
        {
            key: 'assignments',
            label: 'Active assignments',
            value: count(o.active_assignments),
            note: 'Selected assignment in effect',
            alert: o.expired_assignments ? `${count(o.expired_assignments)} expired` : undefined,
            href: link('plans.assign', '/admin/assignments?state=active'),
        },
        {
            key: 'notices',
            label: 'Queued notices',
            value: count(o.pending_notices),
            note: 'Queued, not yet sending',
            alert: o.uncertain_sends ? `${count(o.uncertain_sends)} uncertain` : undefined,
            href: link(
                'notifications.read',
                o.uncertain_sends ? '/admin/notifications?state=uncertain' : '/admin/notifications?state=pending',
            ),
        },
        {
            key: 'failed',
            label: 'Failed jobs',
            value: count(o.failed_jobs),
            note: 'Last 24 hours',
            danger: !!o.failed_jobs,
            href: link('operations.read', '/admin/operations'),
        },
    ];
});
const columns = [
    { key: 'event', label: 'Action' },
    { key: 'actor', label: 'Actor' },
    { key: 'target', label: 'Target' },
    { key: 'occurred_at', label: 'When' },
];
</script>

<template>
    <AdminShell title="Overview" compact>
        <template #description
            >Observed {{ dateTime(overview.observed_at) }} · every figure opens the list it counts</template
        >
        <ul class="rec-stats adm-stats" aria-label="Current counts">
            <li v-for="tile in tiles" :key="tile.key" :class="{ 'is-alert': tile.danger }">
                <component :is="tile.href ? Link : 'div'" v-bind="tile.href ? { href: tile.href } : {}">
                    <span>{{ tile.label }}</span>
                    <strong>{{ tile.value ?? 'Unavailable' }}</strong>
                    <small v-if="tile.value === null">Outside your capabilities</small>
                    <small v-else-if="tile.alert"
                        ><b>{{ tile.alert }}</b></small
                    >
                    <small v-else>{{ tile.note }}</small>
                </component>
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="attention-heading">
            <header class="rec-card-head">
                <h2 id="attention-heading">Needs attention</h2>
                <span v-if="attention.length" class="rec-count">{{ attention.length }}</span>
                <p v-if="attention.length" class="rec-note">Failures first</p>
            </header>
            <EmptyState
                v-if="!attention.length"
                title="Nothing needs attention"
                description="No uncertain sends, failed jobs, expired assignments or readiness gaps were found in the areas you can see."
            />
            <ul v-else class="adm-attention">
                <li v-for="item in attention" :key="item.key">
                    <StatusBadge :label="item.tone === 'danger' ? 'Action needed' : 'Review'" :tone="item.tone" />
                    <div>
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.next }}</p>
                    </div>
                    <Link :href="item.href" class="wd-link" v-bind="{ 'aria-label': item.link }"
                        >{{ item.verb }}<ArrowRight :size="14" aria-hidden="true"
                    /></Link>
                </li>
            </ul>
        </section>
        <section class="rec-card" aria-labelledby="activity-heading">
            <header class="rec-card-head">
                <h2 id="activity-heading">Recent administrative activity</h2>
                <p class="rec-note">
                    <Link v-if="overview.activity?.length" href="/admin/audit" class="wd-link">View the full audit</Link
                    ><template v-else>Denied requests are listed in the audit</template>
                </p>
            </header>
            <UnavailableNotice
                v-if="overview.activity === null"
                title="Activity is outside your capabilities"
                reason="Reviewing the administration audit needs the audit review capability."
            />
            <EmptyState v-else-if="!overview.activity.length" title="No administrative actions recorded yet" />
            <DataTable
                v-else
                class="rec-table adm-records"
                caption="Recent administrative activity"
                :columns="columns"
                :rows="overview.activity"
                :row-key="(row) => row.id"
            >
                <template #cell-event="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/audit/platform/${row.id}`">{{
                        auditEventLabel(row.event)
                    }}</Link></template
                >
                <template #cell-target="{ row }">{{
                    auditTargetLabel(row.target, row.target_kind, row.target_name)
                }}</template>
                <template #cell-occurred_at="{ row }"
                    ><time :datetime="row.occurred_at" :title="dateTime(row.occurred_at)">{{
                        recentTime(row.occurred_at)
                    }}</time></template
                >
            </DataTable>
        </section>
    </AdminShell>
</template>
