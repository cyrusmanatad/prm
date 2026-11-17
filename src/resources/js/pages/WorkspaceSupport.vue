<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import ActionGroup from '../components/ActionGroup.vue';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
import { dateTime, supportAreas } from '../features/admin/format';

type Request = {
    id: string;
    operator: string;
    purpose: string;
    resources: string[];
    hours: number;
    state: string;
    requested_at: string;
    decided_at: string | null;
    decided_by: string | null;
    expires_at: string | null;
    ended_at: string | null;
    end_reason: string | null;
    version: number;
};
const props = defineProps<{ requests: Request[]; workspace: string }>();
const page = usePage<{ account?: { name: string; email: string } | null; errors?: Record<string, string> }>();
const account = computed(() => page.props.account);
const conflict = computed(() => page.props.errors?.expected_version);
const busy = ref(false);
// Owner-facing wording: these describe the workspace's decision, not the operator's view of it.
const states: Record<string, { label: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }> = {
    requested: { label: 'Needs your decision', tone: 'warning' },
    approved: { label: 'Access active', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
    revoked: { label: 'Ended', tone: 'neutral' },
    cancelled: { label: 'Withdrawn by support', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
};
const scope = (request: Request) => request.resources.map((area) => supportAreas[area] ?? area).join(', ');
function decide(request: Request, decision: 'approve' | 'decline' | 'end', event: Event) {
    if (busy.value) return;
    const send = () =>
        router.post(
            `/workspace/support-access/${request.id}`,
            { expected_version: request.version, decision },
            { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) },
        );
    if (decision === 'decline') return send();
    confirmAction(
        decision === 'approve' ? 'Approve support access' : 'End support access',
        decision === 'approve'
            ? `Let ${request.operator} from WeavDash support view ${scope(request)} in ${props.workspace} for ${request.hours} ${request.hours === 1 ? 'hour' : 'hours'}? They can view but not change, download or export. Notes, documents and payment evidence stay hidden. You can end access at any time.`
            : `End ${request.operator}'s access to ${props.workspace} now? They can no longer view any records. A new request would need your approval again.`,
        send,
        event,
        { tone: decision === 'end' ? 'danger' : 'default' },
    );
}
</script>

<template>
    <Head title="Support access requests" />
    <FoundationShell :account-name="account?.name" :account-email="account?.email" @logout="router.post('/logout')">
        <div class="wd-workspace-content wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace }}</span>
                <h1>Support access requests</h1>
                <p class="wd-description">
                    WeavDash support cannot see this workspace's records unless an owner approves a request here.
                    Approved access is view-only, limited to the listed areas, and ends automatically.
                </p>
            </header>
            <p v-if="conflict" class="wd-banner" role="alert">
                {{ conflict }} <Link href="/workspace/support-access" class="wd-link">Reload requests</Link>
            </p>
            <EmptyState
                v-if="!requests.length"
                title="No support access has been requested"
                description="If you contact support and they need to look at your records, their request will appear here for you to approve or decline."
            />
            <ContentSection
                v-for="request in requests"
                :key="request.id"
                :title="`Request from ${request.operator}`"
                :description="`Requested ${dateTime(request.requested_at)}`"
            >
                <p>
                    <StatusBadge
                        :label="states[request.state]?.label ?? request.state"
                        :tone="states[request.state]?.tone ?? 'neutral'"
                    />
                </p>
                <dl class="wd-detail-list">
                    <dt>Purpose</dt>
                    <dd>{{ request.purpose }}</dd>
                    <dt>They could view</dt>
                    <dd>{{ scope(request) }}</dd>
                    <dt>For</dt>
                    <dd>
                        {{ request.hours }} {{ request.hours === 1 ? 'hour' : 'hours'
                        }}<template v-if="request.expires_at">, until {{ dateTime(request.expires_at) }}</template>
                    </dd>
                    <template v-if="request.decided_at">
                        <dt>Decision</dt>
                        <dd>{{ dateTime(request.decided_at) }} by {{ request.decided_by }}</dd>
                    </template>
                    <template v-if="request.ended_at">
                        <dt>Ended</dt>
                        <dd>{{ dateTime(request.ended_at) }}: {{ request.end_reason }}</dd>
                    </template>
                </dl>
                <ActionGroup
                    v-if="request.state === 'requested'"
                    :label="`Decide the request from ${request.operator}`"
                >
                    <Button
                        type="button"
                        class="wd-button wd-button-primary"
                        :disabled="busy"
                        @click="decide(request, 'approve', $event)"
                        >Approve</Button
                    >
                    <Button
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy"
                        @click="decide(request, 'decline', $event)"
                        >Decline</Button
                    >
                </ActionGroup>
                <ActionGroup v-else-if="request.state === 'approved'">
                    <Button
                        type="button"
                        class="wd-button wd-button-danger-outline"
                        :disabled="busy"
                        @click="decide(request, 'end', $event)"
                        >End access now</Button
                    >
                </ActionGroup>
            </ContentSection>
            <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center">Back to workspaces</Link>
        </div>
    </FoundationShell>
</template>
