<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import DetailList from '../components/DetailList.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import TabNav from '../components/TabNav.vue';
import AccessChange, { type AccessImpact } from '../features/admin/AccessChange.vue';
import ActivityList, { type ActivityRow } from '../features/admin/ActivityList.vue';
import BackLink from '../features/admin/BackLink.vue';
import UsageTable from '../features/admin/UsageTable.vue';
import type { Entitlement } from '../features/admin/types';
import { dateTime, entitlementStates, notificationStates, titleCase, workspaceTypes } from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';

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
}>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const base = computed(() => `/admin/workspaces/${props.workspace.id}`);
const tabs = computed(() =>
    [
        ['overview', 'Overview'],
        ['members', 'Members'],
        ['entitlements', 'Entitlements'],
        ['operations', 'Operations'],
        ['activity', 'Activity'],
    ].map(([key, label]) => ({
        href: key === 'overview' ? base.value : `${base.value}?tab=${key}`,
        label: label!,
        current: props.tab === key,
    })),
);
const state = computed(() => entitlementStates[props.entitlement.state]);
const owners = computed(() => props.members.filter((row) => row.role === 'owner' && !row.revoked_at));
const activeMembers = computed(() => props.members.filter((row) => !row.revoked_at).length);
const memberColumns = [
    { key: 'name', label: 'Account' },
    { key: 'role', label: 'Workspace role' },
    { key: 'state', label: 'Membership' },
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
const sum = (counts: Counts) => Object.values(counts).reduce((total, value) => total + value, 0);
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
    <AdminShell
        :title="workspace.name"
        :description="`${workspaceTypes[workspace.kind] ?? workspace.kind} workspace${workspace.experience === 'professional' ? ' · Professional' : ''}`"
        eyebrow="Workspace"
    >
        <template #back><BackLink href="/admin/workspaces" label="All workspaces" /></template>
        <template #actions>
            <StatusBadge v-if="workspace.suspended" label="Suspended" tone="danger" />
            <StatusBadge :label="state?.label ?? entitlement.state" :tone="state?.tone ?? 'neutral'" />
        </template>
        <TabNav label="Workspace sections" :tabs="tabs" />
        <template v-if="tab === 'overview'">
            <ContentSection title="Workspace">
                <DetailList
                    :items="[
                        { key: 'id', label: 'Workspace ID' },
                        { key: 'type', label: 'Type', value: workspaceTypes[workspace.kind] ?? workspace.kind },
                        { key: 'owners', label: 'Owners', value: owners.map((row) => row.name).join(', ') },
                        { key: 'members', label: 'Active members', value: activeMembers },
                        { key: 'entitlement', label: 'Entitlement' },
                        { key: 'created', label: 'Created', value: dateTime(workspace.created_at) },
                        { key: 'updated', label: 'Last updated', value: dateTime(workspace.updated_at) },
                    ]"
                    missing="No active owner"
                >
                    <template #id><CopyReference :value="workspace.id" label="workspace ID" /></template>
                    <template #entitlement
                        >{{ state?.label ?? entitlement.state
                        }}<span v-if="entitlement.plan">
                            · {{ entitlement.plan.name }} v{{ entitlement.plan.version }}</span
                        ></template
                    >
                </DetailList>
                <p class="wd-hint">The workspace type is fixed when it is created and cannot be changed.</p>
            </ContentSection>
            <ContentSection title="Access actions">
                <AccessChange kind="workspace" :reference="workspace.id" :name="workspace.name" :access="access" />
                <p class="wd-hint">
                    Workspaces cannot be deleted, transferred or opened from this console. Viewing private workspace
                    records needs support access that a workspace owner approves.
                </p>
                <Link
                    v-if="allowed(context, 'support.request') && !workspace.suspended"
                    :href="`/admin/support-access?request=${workspace.id}`"
                    class="wd-link inline-flex min-h-11 items-center"
                    >Request support access</Link
                >
            </ContentSection>
        </template>
        <ContentSection
            v-else-if="tab === 'members'"
            title="Members"
            description="Read-only. Changing or revoking a membership from administration is not enabled; workspace owners manage their own members."
        >
            <EmptyState v-if="!members.length" title="This workspace has no members" />
            <DataTable
                v-else
                caption="Members"
                :columns="memberColumns"
                :rows="members"
                :row-key="(row) => row.user_id"
            >
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/accounts/${row.user_id}`">{{ row.name }}</Link
                    ><span class="wd-data-secondary">{{ row.email }}</span></template
                >
                <template #cell-role="{ row }">{{ titleCase(row.role) }}</template>
                <template #cell-state="{ row }"
                    ><StatusBadge v-if="!row.revoked_at" label="Active" tone="success" /><template v-else
                        ><StatusBadge label="Revoked" /><span class="wd-data-secondary">{{
                            dateTime(row.revoked_at)
                        }}</span></template
                    ></template
                >
                <template #cell-created_at="{ row }">{{ dateTime(row.created_at) }}</template>
            </DataTable>
        </ContentSection>
        <template v-else-if="tab === 'entitlements'">
            <ContentSection title="Selected assignment">
                <p>
                    <StatusBadge :label="state?.label ?? entitlement.state" :tone="state?.tone ?? 'neutral'" />
                    {{ state?.meaning }}
                </p>
                <DetailList
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
                            >{{ entitlement.plan.name }} v{{ entitlement.plan.version }}</Link
                        ><template v-else
                            >{{ entitlement.plan.name }} v{{ entitlement.plan.version }}</template
                        ></template
                    >
                </DetailList>
                <div v-if="allowed(context, 'plans.assign')" class="wd-action-group">
                    <Link
                        :href="`/admin/assignments/create?workspace=${workspace.id}`"
                        class="wd-button wd-button-primary"
                        >{{ entitlement.assignment ? 'Replace assignment' : 'Record assignment' }}</Link
                    >
                    <Link
                        :href="`/admin/assignments?workspace=${workspace.id}`"
                        class="wd-link inline-flex min-h-11 items-center"
                        >Assignment history</Link
                    >
                </div>
            </ContentSection>
            <ContentSection
                title="Usage"
                description="Retained record counts against each create limit. Counts only: record contents stay private. Completed reminders and voided finance records still count."
            >
                <UsageTable :entitlement="entitlement" />
            </ContentSection>
        </template>
        <ContentSection
            v-else-if="tab === 'operations'"
            title="Operational summary"
            :description="`Counts by state, observed ${dateTime(operations.observed_at)}. Recipients, filenames, answers and message content are not shown.`"
        >
            <DataTable
                caption="Operational summary"
                :columns="summaryColumns"
                :rows="summary"
                :row-key="(row) => row.key"
            >
                <template #cell-detail="{ row }">{{ describe(row.counts, row.labels) }}</template>
                <template #cell-total="{ row }">{{ sum(row.counts) }}</template>
            </DataTable>
            <Link
                v-if="allowed(context, 'notifications.read')"
                :href="`/admin/notifications?workspace=${workspace.id}`"
                class="wd-link inline-flex min-h-11 items-center"
                >View this workspace's notifications</Link
            >
        </ContentSection>
        <ContentSection v-else title="Administration activity">
            <ActivityList caption="Administration activity for this workspace" :activity="activity" />
        </ContentSection>
    </AdminShell>
</template>
