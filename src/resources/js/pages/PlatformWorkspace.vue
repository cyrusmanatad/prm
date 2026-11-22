<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import TabNav from '../components/TabNav.vue';
import AccessChange, { type AccessImpact } from '../features/admin/AccessChange.vue';
import ActivityList, { type ActivityRow } from '../features/admin/ActivityList.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
import UsageTable from '../features/admin/UsageTable.vue';
import type { Entitlement } from '../features/admin/types';
import {
    dateOnly,
    dateTime,
    entitlementStates,
    notificationStates,
    recentTime,
    titleCase,
    workspaceTypes,
} from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';
import { brandSources } from '../features/brand-requests/types';

type Member = {
    user_id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
    revoked_at: string | null;
};
type Counts = Record<string, number>;
const props = defineProps<{
    workspace: {
        id: string;
        name: string;
        kind: string;
        experience: string;
        created_at: string;
        updated_at: string;
        suspended: boolean;
    };
    access: AccessImpact | null;
    members: Member[];
    entitlement: Entitlement;
    operations: {
        partner_invitations: Counts;
        partnerships: Counts;
        onboarding_requests: Counts;
        onboarding_documents: Counts;
        notifications: Counts;
        observed_at: string;
    };
    activity: ActivityRow[] | null;
    tab: string;
    /** Brand workspaces only. `unrecorded` means the workspace predates review and the rollout has not recorded it. */
    approval?: {
        status: 'approved' | 'unrecorded';
        source?: string;
        request?: string;
        approved_at?: string;
        reviewer?: string | null;
        operator?: string | null;
        reason?: string;
    } | null;
    canReviewBrands?: boolean;
}>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const base = computed(() => `/admin/workspaces/${props.workspace.id}`);
const at = (tab: string) => (tab === 'overview' ? base.value : `${base.value}?tab=${tab}`);
const state = computed(() => entitlementStates[props.entitlement.state]);
const owners = computed(() => props.members.filter((row) => row.role === 'owner' && !row.revoked_at));
const activeMembers = computed(() => props.members.filter((row) => !row.revoked_at).length);
const type = computed(
    () =>
        `${workspaceTypes[props.workspace.kind] ?? props.workspace.kind}${props.workspace.experience === 'professional' ? ' · Professional' : ''}`,
);
const plan = computed(() =>
    props.entitlement.plan ? `${props.entitlement.plan.name} v${props.entitlement.plan.version}` : null,
);
const sum = (counts: Counts) => Object.values(counts).reduce((total, value) => total + value, 0);
const tabs = computed(() =>
    [
        ['overview', 'Overview'],
        ['members', `Members ${activeMembers.value}`],
        ['entitlements', 'Entitlements'],
        ['operations', 'Operations'],
        ['activity', 'Activity'],
    ].map(([key, label]) => ({ href: at(key!), label: label!, current: props.tab === key })),
);
// Each tile opens the tab or page that explains it. Brand workspaces show their approval; others their partnerships.
const tiles = computed(() => [
    {
        key: 'members',
        label: 'Members',
        value: String(activeMembers.value),
        note: owners.value.length ? `Owner: ${owners.value.map((row) => row.name).join(', ')}` : 'No active owner',
        href: at('members'),
    },
    {
        key: 'entitlement',
        label: 'Entitlement',
        value: state.value?.label ?? props.entitlement.state,
        note: plan.value ?? 'No plan selected',
        href: at('entitlements'),
    },
    props.approval
        ? {
              key: 'approval',
              label: 'Brand approval',
              value: props.approval.status === 'approved' ? 'Approved' : 'Not recorded',
              note:
                  props.approval.status === 'approved'
                      ? `${brandSources[props.approval.source ?? ''] ?? props.approval.source} · ${dateOnly(props.approval.approved_at)}`
                      : 'Created before review',
              href: base.value,
          }
        : {
              key: 'partnerships',
              label: 'Partnerships',
              value: String(sum(props.operations.partnerships)),
              note: `${sum(props.operations.partner_invitations)} invitations sent`,
              href: at('operations'),
          },
    {
        key: 'notifications',
        label: 'Notifications',
        value: String(sum(props.operations.notifications)),
        note: `${props.operations.notifications.uncertain ?? 0} uncertain sends`,
        href: allowed(context.value, 'notifications.read')
            ? `/admin/notifications?workspace=${props.workspace.id}`
            : at('operations'),
    },
]);
const memberColumns = [
    { key: 'name', label: 'Account' },
    { key: 'state', label: 'Status' },
    { key: 'role', label: 'Workspace role' },
    { key: 'created_at', label: 'Joined' },
];
const summaryColumns = [
    { key: 'label', label: 'Area' },
    { key: 'detail', label: 'Counts by state' },
    { key: 'total', label: 'Total', numeric: true },
];
function describe(counts: Counts, labels: Record<string, string> = {}): string {
    const parts = Object.entries(counts).map(([key, total]) => `${labels[key] ?? titleCase(key)} ${total}`);
    return parts.length ? parts.join(' · ') : 'None recorded';
}
const summary = computed(() => [
    { key: 'invitations', label: 'Partner invitations sent', counts: props.operations.partner_invitations, labels: {} },
    { key: 'partnerships', label: 'Partnerships', counts: props.operations.partnerships, labels: {} },
    { key: 'onboarding', label: 'Onboarding requests', counts: props.operations.onboarding_requests, labels: {} },
    {
        key: 'documents',
        label: 'Onboarding documents by scan state',
        counts: props.operations.onboarding_documents,
        labels: {},
    },
    {
        key: 'notifications',
        label: 'Notifications',
        counts: props.operations.notifications,
        labels: Object.fromEntries(Object.entries(notificationStates).map(([key, entry]) => [key, entry.label])),
    },
]);
</script>

<template>
    <AdminShell :title="workspace.name" compact>
        <template #back><BackLink href="/admin/workspaces" label="All workspaces" /></template>
        <template #description
            ><span class="adm-meta"
                ><span>{{ type }} workspace · Created {{ dateOnly(workspace.created_at) }} · Workspace ID</span
                ><CopyReference :value="workspace.id" label="workspace ID" /></span
        ></template>
        <template #actions>
            <div class="rec-header-actions">
                <StatusBadge :label="state?.label ?? entitlement.state" :tone="state?.tone ?? 'neutral'" />
                <StatusBadge
                    :label="workspace.suspended ? 'Suspended' : 'Active'"
                    :tone="workspace.suspended ? 'danger' : 'neutral'"
                />
            </div>
        </template>
        <ul class="rec-stats adm-stats adm-stats-4" aria-label="Workspace summary">
            <li v-for="tile in tiles" :key="tile.key">
                <Link :href="tile.href">
                    <span>{{ tile.label }}</span>
                    <strong>{{ tile.value }}</strong>
                    <small>{{ tile.note }}</small>
                </Link>
            </li>
        </ul>
        <TabNav label="Workspace sections" :tabs="tabs" />
        <template v-if="tab === 'overview'">
            <section class="rec-card" aria-labelledby="workspace-heading">
                <header class="rec-card-head">
                    <h2 id="workspace-heading">Workspace</h2>
                    <p class="rec-note">The type is fixed when the workspace is created</p>
                </header>
                <FactList
                    missing="No active owner"
                    :items="[
                        { key: 'type', label: 'Type', value: type },
                        { key: 'owners', label: 'Owners', value: owners.length ? 'owners' : null },
                        { key: 'members', label: 'Active members', value: activeMembers },
                        { key: 'entitlement', label: 'Entitlement' },
                        { key: 'created', label: 'Created', value: dateTime(workspace.created_at) },
                        { key: 'updated', label: 'Last updated', value: dateTime(workspace.updated_at) },
                    ]"
                >
                    <template v-if="owners.length" #owners
                        ><template v-for="(owner, index) in owners" :key="owner.user_id"
                            ><template v-if="index">, </template
                            ><Link :href="`/admin/accounts/${owner.user_id}`" class="wd-link">{{
                                owner.name
                            }}</Link></template
                        ></template
                    >
                    <template #entitlement
                        >{{ state?.label ?? entitlement.state }}<template v-if="plan"> · {{ plan }}</template></template
                    >
                </FactList>
            </section>
            <section v-if="approval" class="rec-card" aria-labelledby="approval-heading">
                <header class="rec-card-head">
                    <h2 id="approval-heading">Brand approval</h2>
                    <StatusBadge
                        :label="approval.status === 'approved' ? 'Approved' : 'No approval recorded'"
                        :tone="approval.status === 'approved' ? 'success' : 'warning'"
                    />
                    <p class="rec-note">
                        <Link
                            v-if="canReviewBrands && approval.request"
                            :href="`/admin/brand-requests/${approval.request}`"
                            class="wd-link"
                            >{{ approval.source === 'rollout' ? 'View approval record' : 'View the request' }}</Link
                        ><template v-else>Not a verified-business badge and not a subscription</template>
                    </p>
                </header>
                <FactList
                    v-if="approval.status === 'approved'"
                    :items="[
                        {
                            key: 'source',
                            label: 'Source',
                            value: brandSources[approval.source ?? ''] ?? approval.source,
                        },
                        { key: 'reviewer', label: 'Approved by', value: approval.reviewer },
                        {
                            key: 'operator',
                            label: 'Recorded by',
                            value: approval.operator ?? 'The reviewer, in this console',
                        },
                        { key: 'approved', label: 'Approved', value: dateTime(approval.approved_at) },
                        { key: 'reason', label: 'Reason', value: approval.reason, wide: true },
                    ]"
                />
                <p v-if="approval.status !== 'approved'" class="rec-fine">
                    This workspace was created before review was required and keeps its access. The offline rollout
                    command records its approval.
                </p>
                <p v-else-if="approval.source === 'rollout'" class="rec-fine">
                    This workspace existed before review was required. Its approval was recorded during the rollout, not
                    from a signup review, and its creation date is unchanged.
                </p>
            </section>
            <section class="rec-card" aria-labelledby="access-heading">
                <header class="rec-card-head">
                    <h2 id="access-heading">Access</h2>
                    <p class="rec-note">Each change needs a reason and a fresh code, then a confirmation</p>
                </header>
                <AccessChange kind="workspace" :reference="workspace.id" :name="workspace.name" :access="access" />
                <div v-if="allowed(context, 'support.request') && !workspace.suspended" class="adm-action-row">
                    <div>
                        <h3>Request support access</h3>
                        <p>Read-only, approved by a workspace owner, for up to 24 hours.</p>
                    </div>
                    <Link :href="`/admin/support-access?request=${workspace.id}`" class="wd-button wd-button-secondary"
                        >Request support access</Link
                    >
                </div>
                <p class="rec-fine">
                    Workspaces can't be deleted, transferred or opened from this console. Viewing private workspace
                    records needs support access that a workspace owner approves.
                </p>
            </section>
        </template>
        <section v-else-if="tab === 'members'" class="rec-card" aria-labelledby="members-heading">
            <header class="rec-card-head">
                <h2 id="members-heading">Members</h2>
                <span class="rec-count">{{ members.length }}</span>
                <p class="rec-note">Read-only · workspace owners manage their own members</p>
            </header>
            <EmptyState v-if="!members.length" title="This workspace has no members" />
            <DataTable
                v-else
                class="rec-table"
                caption="Members"
                :columns="memberColumns"
                :rows="members"
                :row-key="(row) => row.user_id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/accounts/${row.user_id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub">{{ row.email }}</span></template
                >
                <template #cell-state="{ row }"
                    ><StatusBadge v-if="!row.revoked_at" label="Active" tone="success" /><template v-else
                        ><StatusBadge label="Revoked" /><span class="rec-row-sub">{{
                            dateOnly(row.revoked_at)
                        }}</span></template
                    ></template
                >
                <template #cell-role="{ row }">{{ titleCase(row.role) }}</template>
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        recentTime(row.created_at)
                    }}</time></template
                >
            </DataTable>
        </section>
        <template v-else-if="tab === 'entitlements'">
            <section class="rec-card" aria-labelledby="assignment-heading">
                <header class="rec-card-head">
                    <h2 id="assignment-heading">Selected assignment</h2>
                    <StatusBadge :label="state?.label ?? entitlement.state" :tone="state?.tone ?? 'neutral'" />
                    <p v-if="allowed(context, 'plans.assign')" class="rec-note">
                        <Link :href="`/admin/assignments?workspace=${workspace.id}`" class="wd-link"
                            >Assignment history</Link
                        >
                    </p>
                </header>
                <p class="rec-fine">{{ state?.meaning }}</p>
                <FactList
                    v-if="entitlement.assignment && entitlement.plan"
                    :items="[
                        { key: 'plan', label: 'Plan' },
                        { key: 'version', label: 'Assignment version', value: entitlement.assignment.version },
                        { key: 'starts', label: 'Starts', value: dateTime(entitlement.assignment.starts_at) },
                        { key: 'ends', label: 'Ends', value: dateTime(entitlement.assignment.ends_at) },
                        { key: 'recorded', label: 'Recorded', value: dateTime(entitlement.assignment.created_at) },
                    ]"
                >
                    <template #plan
                        ><Link
                            v-if="allowed(context, 'plans.assign')"
                            :href="`/admin/plans/${entitlement.plan.id}`"
                            class="wd-link"
                            >{{ plan }}</Link
                        ><template v-else>{{ plan }}</template></template
                    >
                </FactList>
                <div v-if="allowed(context, 'plans.assign')" class="adm-action-row">
                    <div>
                        <h3>{{ entitlement.assignment ? 'Replace assignment' : 'Record assignment' }}</h3>
                        <p>A new assignment version is recorded; earlier versions stay in the history.</p>
                    </div>
                    <Link
                        :href="`/admin/assignments/create?workspace=${workspace.id}`"
                        class="wd-button wd-button-secondary"
                        >{{ entitlement.assignment ? 'Replace assignment' : 'Record assignment' }}</Link
                    >
                </div>
            </section>
            <section class="rec-card" aria-labelledby="usage-heading">
                <header class="rec-card-head">
                    <h2 id="usage-heading">Usage</h2>
                    <p class="rec-note">Retained record counts against each create limit · contents stay private</p>
                </header>
                <UsageTable class="rec-table" :entitlement="entitlement" />
                <p class="rec-fine">Completed reminders and voided finance records still count.</p>
            </section>
        </template>
        <section v-else-if="tab === 'operations'" class="rec-card" aria-labelledby="operations-heading">
            <header class="rec-card-head">
                <h2 id="operations-heading">Operational summary</h2>
                <p class="rec-note">
                    Observed {{ dateTime(operations.observed_at)
                    }}<template v-if="allowed(context, 'notifications.read')">
                        ·
                        <Link :href="`/admin/notifications?workspace=${workspace.id}`" class="wd-link"
                            >View this workspace's notifications</Link
                        ></template
                    >
                </p>
            </header>
            <DataTable
                class="rec-table adm-no-status"
                caption="Operational summary"
                :columns="summaryColumns"
                :rows="summary"
                :row-key="(row) => row.key"
            >
                <template #cell-label="{ row }"
                    ><span class="rec-row-name">{{ row.label }}</span></template
                >
                <template #cell-detail="{ row }">{{ describe(row.counts, row.labels) }}</template>
                <template #cell-total="{ row }">{{ sum(row.counts) }}</template>
            </DataTable>
            <p class="rec-fine">Counts only. Recipients, filenames, answers and message content are not shown.</p>
        </section>
        <section v-else class="rec-card" aria-labelledby="activity-heading">
            <header class="rec-card-head">
                <h2 id="activity-heading">Administration activity</h2>
                <p class="rec-note">
                    Actions that targeted this workspace · activity inside the workspace is not shown
                </p>
            </header>
            <ActivityList class="rec-table" caption="Administration activity for this workspace" :activity="activity" />
        </section>
    </AdminShell>
</template>
