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
import { capabilityNames, dateTime, titleCase, workspaceTypes } from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';

type Membership = {
    org_id: string;
    name: string;
    kind: string;
    role: string;
    created_at: string;
    revoked_at: string | null;
};
const props = defineProps<{
    account: {
        id: number;
        name: string;
        email: string;
        email_verified_at: string | null;
        created_at: string;
        updated_at: string;
        suspended: boolean;
    };
    security: {
        mfa_enrolled: boolean;
        mfa_enrolled_at: string | null;
        sessions: number;
        grant: { permissions: string[]; revoked_at: string | null } | null;
    };
    memberships: Membership[];
    activity: ActivityRow[] | null;
    tab: string;
    access: AccessImpact | null;
}>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const base = computed(() => `/admin/accounts/${props.account.id}`);
const tabs = computed(() =>
    [
        ['overview', 'Overview'],
        ['workspaces', 'Workspaces'],
        ['security', 'Security'],
        ['activity', 'Activity'],
    ].map(([key, label]) => ({
        href: key === 'overview' ? base.value : `${base.value}?tab=${key}`,
        label: label!,
        current: props.tab === key,
    })),
);
const active = computed(() => props.memberships.filter((row) => !row.revoked_at).length);
const grantActive = computed(() => !!props.security.grant && !props.security.grant.revoked_at);
const columns = [
    { key: 'name', label: 'Workspace' },
    { key: 'role', label: 'Workspace role' },
    { key: 'state', label: 'Membership' },
    { key: 'created_at', label: 'Joined' },
];
</script>

<template>
    <AdminShell :title="account.name" :description="account.email" eyebrow="Account">
        <template #back><BackLink href="/admin/accounts" label="All accounts" /></template>
        <template #actions>
            <StatusBadge v-if="account.suspended" label="Suspended" tone="danger" />
            <StatusBadge
                :label="account.email_verified_at ? 'Verified' : 'Unverified'"
                :tone="account.email_verified_at ? 'success' : 'warning'"
            />
        </template>
        <TabNav label="Account sections" :tabs="tabs" />
        <template v-if="tab === 'overview'">
            <ContentSection title="Identity">
                <DetailList
                    :items="[
                        { key: 'id', label: 'Account ID' },
                        { key: 'email', label: 'Email', value: account.email },
                        {
                            key: 'verified',
                            label: 'Email verified',
                            value: dateTime(account.email_verified_at, 'Not verified'),
                        },
                        { key: 'created', label: 'Joined', value: dateTime(account.created_at) },
                        { key: 'updated', label: 'Last updated', value: dateTime(account.updated_at) },
                        { key: 'workspaces', label: 'Active workspaces', value: active },
                        { key: 'sign_in', label: 'Last successful sign-in', value: null },
                    ]"
                    missing="Not collected"
                >
                    <template #id><CopyReference :value="String(account.id)" label="account ID" /></template>
                </DetailList>
            </ContentSection>
            <ContentSection title="Access actions">
                <AccessChange kind="account" :reference="String(account.id)" :name="account.name" :access="access" />
                <p class="wd-hint">
                    Identity details cannot be edited here, email cannot be marked verified, and accounts cannot be
                    deleted or impersonated.
                </p>
            </ContentSection>
        </template>
        <ContentSection
            v-else-if="tab === 'workspaces'"
            title="Workspace memberships"
            description="A workspace role applies inside that workspace only and never grants platform access."
        >
            <EmptyState v-if="!memberships.length" title="This account belongs to no workspace" />
            <DataTable
                v-else
                caption="Workspace memberships"
                :columns="columns"
                :rows="memberships"
                :row-key="(row) => row.org_id"
            >
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/workspaces/${row.org_id}`">{{ row.name }}</Link
                    ><span class="wd-data-secondary">{{ workspaceTypes[row.kind] ?? row.kind }}</span></template
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
        <template v-else-if="tab === 'security'">
            <ContentSection title="Sign-in security">
                <DetailList
                    :items="[
                        { key: 'verified', label: 'Email verification' },
                        { key: 'sessions', label: 'Sessions on record', value: security.sessions },
                    ]"
                >
                    <template #verified
                        ><StatusBadge
                            :label="account.email_verified_at ? 'Verified' : 'Unverified'"
                            :tone="account.email_verified_at ? 'success' : 'warning'"
                    /></template>
                </DetailList>
                <p class="wd-hint">
                    Session identifiers, network addresses and devices are not shown. Sessions can be signed out from
                    Access actions on the Overview tab.
                </p>
            </ContentSection>
            <ContentSection title="Platform access">
                <template v-if="security.grant">
                    <DetailList
                        :items="[
                            { key: 'state', label: 'Grant' },
                            { key: 'capabilities', label: 'Capabilities' },
                            { key: 'mfa', label: 'Authenticator' },
                            {
                                key: 'enrolled',
                                label: 'Enrolled',
                                value: dateTime(security.mfa_enrolled_at, 'Not enrolled'),
                            },
                        ]"
                    >
                        <template #state
                            ><StatusBadge v-if="grantActive" label="Active" tone="success" /><template v-else
                                ><StatusBadge label="Revoked" /> {{ dateTime(security.grant.revoked_at) }}</template
                            ></template
                        >
                        <template #capabilities>{{ capabilityNames(security.grant.permissions) }}</template>
                        <template #mfa
                            ><StatusBadge
                                :label="security.mfa_enrolled ? 'Enrolled' : 'Not enrolled — grant cannot be used yet'"
                                :tone="security.mfa_enrolled ? 'success' : 'warning'"
                        /></template>
                    </DetailList>
                </template>
                <p v-else>This account has no platform grant. An authenticator is required only for platform access.</p>
                <Link
                    v-if="allowed(context, 'platform.owner')"
                    :href="`/admin/operators?account=${account.id}`"
                    class="wd-link inline-flex min-h-11 items-center"
                    >{{ security.grant ? 'Manage platform access' : 'Grant platform access' }}</Link
                >
            </ContentSection>
        </template>
        <ContentSection v-else title="Administration activity">
            <ActivityList caption="Administration activity for this account" :activity="activity" />
        </ContentSection>
    </AdminShell>
</template>
