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
import AuthenticatorReset from '../features/admin/AuthenticatorReset.vue';
import ActivityList, { type ActivityRow } from '../features/admin/ActivityList.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
import { capabilityNames, dateOnly, dateTime, recentTime, titleCase, workspaceTypes } from '../features/admin/format';
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
const at = (tab: string) => (tab === 'overview' ? base.value : `${base.value}?tab=${tab}`);
const active = computed(() => props.memberships.filter((row) => !row.revoked_at));
const owned = computed(() => active.value.filter((row) => row.role === 'owner').length);
const grantActive = computed(() => !!props.security.grant && !props.security.grant.revoked_at);
const canGrant = computed(() => allowed(context.value, 'platform.owner'));
const tabs = computed(() =>
    [
        ['overview', 'Overview'],
        ['workspaces', `Workspaces ${active.value.length}`],
        ['security', 'Security'],
        ['activity', 'Activity'],
    ].map(([key, label]) => ({ href: at(key!), label: label!, current: props.tab === key })),
);
// Each tile opens the tab or page that explains it.
const tiles = computed(() => [
    {
        key: 'workspaces',
        label: 'Workspaces',
        value: String(active.value.length),
        note: `${owned.value} owned · ${active.value.length - owned.value} as member`,
        href: at('workspaces'),
    },
    {
        key: 'sessions',
        label: 'Sessions on record',
        value: String(props.security.sessions),
        note: 'Devices are not shown',
        href: at('security'),
    },
    {
        key: 'mfa',
        label: 'Authenticator',
        value: props.security.mfa_enrolled ? 'Set up' : 'Not set up',
        note: props.security.mfa_enrolled_at ? `Since ${dateOnly(props.security.mfa_enrolled_at)}` : 'No code required',
        href: at('security'),
    },
    {
        key: 'grant',
        label: 'Platform access',
        value: grantActive.value ? 'Active' : props.security.grant ? 'Revoked' : 'None',
        note: grantActive.value
            ? capabilityNames(props.security.grant!.permissions)
            : canGrant.value
              ? 'Grant platform access'
              : 'No platform grant',
        href: canGrant.value ? `/admin/operators?account=${props.account.id}` : at('security'),
    },
]);
const columns = [
    { key: 'name', label: 'Workspace' },
    { key: 'state', label: 'Status' },
    { key: 'role', label: 'Workspace role' },
    { key: 'created_at', label: 'Joined' },
];
</script>

<template>
    <AdminShell :title="account.name" compact>
        <template #back><BackLink href="/admin/accounts" label="All accounts" /></template>
        <template #description
            ><span class="adm-meta"
                ><span>{{ account.email }} · Joined {{ dateOnly(account.created_at) }} · Account ID</span
                ><CopyReference :value="String(account.id)" label="account ID" /></span
        ></template>
        <template #actions>
            <div class="rec-header-actions">
                <StatusBadge
                    :label="account.email_verified_at ? 'Verified' : 'Unverified'"
                    :tone="account.email_verified_at ? 'success' : 'warning'"
                />
                <StatusBadge
                    :label="account.suspended ? 'Suspended' : 'Active'"
                    :tone="account.suspended ? 'danger' : 'neutral'"
                />
            </div>
        </template>
        <ul class="rec-stats adm-stats adm-stats-4" aria-label="Account summary">
            <li v-for="tile in tiles" :key="tile.key">
                <Link :href="tile.href">
                    <span>{{ tile.label }}</span>
                    <strong>{{ tile.value }}</strong>
                    <small>{{ tile.note }}</small>
                </Link>
            </li>
        </ul>
        <TabNav label="Account sections" :tabs="tabs" />
        <template v-if="tab === 'overview'">
            <section class="rec-card" aria-labelledby="identity-heading">
                <header class="rec-card-head">
                    <h2 id="identity-heading">Identity</h2>
                    <p class="rec-note">Read-only · the account holder edits their own details</p>
                </header>
                <FactList
                    missing="Not collected"
                    :items="[
                        { key: 'email', label: 'Email', value: account.email },
                        {
                            key: 'verified',
                            label: 'Email verified',
                            value: dateTime(account.email_verified_at, 'Not verified'),
                        },
                        { key: 'created', label: 'Joined', value: dateTime(account.created_at) },
                        { key: 'updated', label: 'Last updated', value: dateTime(account.updated_at) },
                        { key: 'workspaces', label: 'Active workspaces', value: active.length },
                        { key: 'sign_in', label: 'Last successful sign-in', value: null },
                    ]"
                />
            </section>
            <section class="rec-card" aria-labelledby="access-heading">
                <header class="rec-card-head">
                    <h2 id="access-heading">Access</h2>
                    <p class="rec-note">Each change needs a reason and a fresh code, then a confirmation</p>
                </header>
                <AccessChange kind="account" :reference="String(account.id)" :name="account.name" :access="access" />
                <p class="rec-fine">
                    Identity details can't be edited here, email can't be marked verified, and accounts can't be deleted
                    or impersonated.
                </p>
            </section>
        </template>
        <section v-else-if="tab === 'workspaces'" class="rec-card" aria-labelledby="memberships-heading">
            <header class="rec-card-head">
                <h2 id="memberships-heading">Workspace memberships</h2>
                <span class="rec-count">{{ memberships.length }}</span>
                <p class="rec-note">
                    A workspace role applies inside that workspace only and never grants platform access
                </p>
            </header>
            <EmptyState v-if="!memberships.length" title="This account belongs to no workspace" />
            <DataTable
                v-else
                class="rec-table"
                caption="Workspace memberships"
                :columns="columns"
                :rows="memberships"
                :row-key="(row) => row.org_id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/workspaces/${row.org_id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub">{{ workspaceTypes[row.kind] ?? row.kind }}</span></template
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
        <template v-else-if="tab === 'security'">
            <section class="rec-card" aria-labelledby="sign-in-heading">
                <header class="rec-card-head">
                    <h2 id="sign-in-heading">Sign-in security</h2>
                    <p class="rec-note">Session identifiers, network addresses and devices are not shown</p>
                </header>
                <FactList
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
                </FactList>
                <p class="rec-fine">Sessions can be signed out from Access on the Overview tab.</p>
            </section>
            <section class="rec-card" aria-labelledby="authenticator-heading">
                <header class="rec-card-head">
                    <h2 id="authenticator-heading">Authenticator</h2>
                    <p class="rec-note">Workspace owners and platform operators sign in with an authenticator code</p>
                </header>
                <FactList
                    :items="[
                        { key: 'mfa', label: 'Authenticator' },
                        { key: 'enrolled', label: 'Set up', value: dateTime(security.mfa_enrolled_at, 'Not set up') },
                    ]"
                >
                    <template #mfa
                        ><StatusBadge
                            :label="security.mfa_enrolled ? 'Set up' : 'Not set up'"
                            :tone="security.mfa_enrolled ? 'success' : 'neutral'"
                    /></template>
                </FactList>
                <AuthenticatorReset
                    :account="account.id"
                    :name="account.name"
                    :enrolled="security.mfa_enrolled"
                    :platform-grant="grantActive"
                    :self="access?.self ?? false"
                    :can-manage="access !== null"
                />
                <p class="rec-fine">
                    Setup keys and recovery codes are never shown here. A reset is recorded in the administration audit.
                </p>
            </section>
            <section class="rec-card" aria-labelledby="grant-heading">
                <header class="rec-card-head">
                    <h2 id="grant-heading">Platform access</h2>
                    <p v-if="canGrant" class="rec-note">
                        <Link :href="`/admin/operators?account=${account.id}`" class="wd-link">{{
                            security.grant ? 'Manage platform access' : 'Grant platform access'
                        }}</Link>
                    </p>
                </header>
                <FactList
                    v-if="security.grant"
                    :items="[
                        { key: 'state', label: 'Grant' },
                        { key: 'mfa', label: 'Authenticator' },
                        { key: 'capabilities', label: 'Capabilities', wide: true },
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
                </FactList>
                <p v-else class="rec-fine">This account has no platform grant.</p>
            </section>
        </template>
        <section v-else class="rec-card" aria-labelledby="activity-heading">
            <header class="rec-card-head">
                <h2 id="activity-heading">Administration activity</h2>
                <p class="rec-note">Actions that targeted this account</p>
            </header>
            <ActivityList class="rec-table" caption="Administration activity for this account" :activity="activity" />
        </section>
    </AdminShell>
</template>
