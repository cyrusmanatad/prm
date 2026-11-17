<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import StatCard from '../components/StatCard.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { age, dateTime } from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';

type Activity = { id: string; occurred_at: string; event: string; target: string; actor: string };
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
        activity: Activity[] | null;
    };
}>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const can = (capability: string) => allowed(context.value, capability);
const observed = computed(() => `Observed ${dateTime(props.overview.observed_at)}`);
const scope = (capability: string, text: string) =>
    can(capability) ? `${text} · ${observed.value}` : 'Outside your capabilities';
type Attention = { key: string; tone: 'warning' | 'danger'; title: string; next: string; href: string; link: string };
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
    if (o.support_awaiting)
        items.push({
            key: 'support',
            tone: 'warning',
            title: `${o.support_awaiting} support ${o.support_awaiting === 1 ? 'request is' : 'requests are'} waiting for a workspace owner`,
            next: 'Only a workspace owner can approve. Withdraw requests that are no longer needed.',
            href: '/admin/support-access?state=requested',
            link: 'View support requests',
        });
    return items;
});
const columns = [
    { key: 'event', label: 'Action' },
    { key: 'actor', label: 'Actor' },
    { key: 'target', label: 'Target' },
    { key: 'occurred_at', label: 'Occurred' },
];
</script>

<template>
    <AdminShell
        title="Overview"
        description="Operational counts and items that need attention. Every figure links to the list it summarises."
    >
        <ContentSection title="Needs attention">
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
                    <Link :href="item.href" class="wd-link inline-flex min-h-11 items-center">{{ item.link }}</Link>
                </li>
            </ul>
        </ContentSection>
        <section aria-labelledby="overview-counts">
            <h2 id="overview-counts" class="wd-sr-only">Current counts</h2>
            <div class="wd-stat-grid">
                <StatCard
                    label="Accounts"
                    :value="overview.accounts"
                    :scope="scope('directory.read', 'All registered identities')"
                    :href="can('directory.read') ? '/admin/accounts' : undefined"
                />
                <StatCard
                    label="Creator workspaces"
                    :value="overview.creator_workspaces"
                    :scope="scope('directory.read', 'All creator workspaces')"
                    :href="can('directory.read') ? '/admin/workspaces?kind=creator' : undefined"
                />
                <StatCard
                    label="Brand workspaces"
                    :value="overview.brand_workspaces"
                    :scope="scope('directory.read', 'All brand workspaces')"
                    :href="can('directory.read') ? '/admin/workspaces?kind=brand' : undefined"
                />
                <StatCard
                    label="Active assignments"
                    :value="overview.active_assignments"
                    :scope="scope('plans.assign', 'Workspaces whose selected assignment is in effect')"
                    :href="can('plans.assign') ? '/admin/assignments?state=active' : undefined"
                />
                <StatCard
                    label="Queued notices"
                    :value="overview.pending_notices"
                    :scope="scope('notifications.read', 'Queued, not yet sending')"
                    :href="can('notifications.read') ? '/admin/notifications?state=pending' : undefined"
                />
                <StatCard
                    label="Uncertain sends"
                    :value="overview.uncertain_sends"
                    :scope="scope('notifications.read', 'Awaiting a recorded resolution')"
                    :href="can('notifications.read') ? '/admin/notifications?state=uncertain' : undefined"
                />
                <StatCard
                    label="Failed jobs"
                    :value="overview.failed_jobs"
                    :scope="scope('operations.read', 'Last 24 hours')"
                    :href="can('operations.read') ? '/admin/operations' : undefined"
                />
            </div>
        </section>
        <ContentSection
            title="Recent administrative activity"
            description="The latest recorded administration actions. Denied requests are listed in the audit."
        >
            <UnavailableNotice
                v-if="overview.activity === null"
                title="Activity is outside your capabilities"
                reason="Reviewing the administration audit needs the audit review capability."
            />
            <EmptyState v-else-if="!overview.activity.length" title="No administrative actions recorded yet" />
            <template v-else>
                <DataTable
                    caption="Recent administrative activity"
                    :columns="columns"
                    :rows="overview.activity"
                    :row-key="(row) => row.id"
                >
                    <template #cell-event="{ row }"
                        ><Link :href="`/admin/audit/platform/${row.id}`">{{ row.event }}</Link></template
                    >
                    <template #cell-occurred_at="{ row }">{{ dateTime(row.occurred_at) }}</template>
                </DataTable>
                <Link href="/admin/audit" class="wd-link inline-flex min-h-11 items-center">View the full audit</Link>
            </template>
        </ContentSection>
    </AdminShell>
</template>
