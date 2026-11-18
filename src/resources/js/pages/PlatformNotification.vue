<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import DetailList from '../components/DetailList.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import BackLink from '../features/admin/BackLink.vue';
import ResolutionForm from '../features/platform-notifications/ResolutionForm.vue';
import {
    deferralReasons,
    resolutionOptions as resolutions,
    type DeliveryDetail,
} from '../features/platform-notifications/types';
import { dateTime, notificationStates as states } from '../features/admin/format';
import { allowed, type AdminContext } from '../features/admin/navigation';

const props = defineProps<{ notice: DeliveryDetail; canResolve: boolean }>();
const context = computed(() => usePage<{ admin?: AdminContext }>().props.admin);
const state = computed(() => states[props.notice.state]);
const resolution = computed(() => resolutions.find((option) => option.value === props.notice.resolution)?.label);
const open = computed(() => props.notice.state === 'uncertain' && !props.notice.resolution);
</script>

<template>
    <AdminShell
        :title="notice.kind"
        :description="`Notification for ${notice.workspace ?? `the account ${notice.account?.name}`}`"
        eyebrow="Notification"
    >
        <template #back><BackLink href="/admin/notifications" label="All notifications" /></template>
        <template #actions>
            <StatusBadge :label="state?.label ?? notice.state" :tone="state?.tone ?? 'neutral'" />
        </template>
        <ContentSection title="Delivery state">
            <p v-if="!open">{{ state?.meaning }}</p>
            <p v-if="resolution" class="wd-status" role="status">
                Recorded resolution: {{ resolution }}. No resend authorized.
            </p>
            <p v-else-if="open" class="wd-warning">
                The mail transport did not confirm whether this message was sent, so it may already have reached the
                recipient. It is never retried automatically. Check the mail provider's records for this time, then
                record what you found.
                {{
                    notice.account
                        ? 'It is not sent again: the account sees the outcome in the application.'
                        : 'To send again, the workspace must create a new invitation or approve a fresh draft.'
                }}
            </p>
            <DetailList
                :items="[
                    { key: 'id', label: 'Notification ID' },
                    { key: 'workspace', label: notice.account ? 'Account' : 'Workspace' },
                    { key: 'actor', label: 'Requested by', value: notice.actor?.name },
                    { key: 'created', label: 'Queued', value: dateTime(notice.created_at) },
                    { key: 'started', label: 'Send started', value: dateTime(notice.started_at, 'Not started') },
                    { key: 'completed', label: 'Completed', value: dateTime(notice.completed_at, 'Not completed') },
                    { key: 'deferrals', label: 'Deferrals', value: notice.defer_count },
                    {
                        key: 'deferral',
                        label: 'Last deferral',
                        value: notice.failure_code
                            ? `${deferralReasons[notice.failure_code] ?? 'Not classified'}. Eligible again ${dateTime(notice.available_at)}.`
                            : 'None',
                    },
                    { key: 'resolved', label: 'Resolution recorded', value: dateTime(notice.resolved_at, 'None') },
                    { key: 'resolver', label: 'Resolved by', value: notice.resolver ?? 'Nobody' },
                    { key: 'recipient', label: 'Recipient and provider receipt', value: 'Not available' },
                ]"
            >
                <template #id><CopyReference :value="notice.id" label="notification ID" /></template>
                <template #workspace
                    ><Link
                        v-if="allowed(context, 'directory.read')"
                        :href="
                            notice.account
                                ? `/admin/accounts/${notice.account.id}`
                                : `/admin/workspaces/${notice.org_id}`
                        "
                        class="wd-link"
                        >{{ notice.account?.name ?? notice.workspace }}</Link
                    ><template v-else>{{ notice.account?.name ?? notice.workspace }}</template></template
                >
            </DetailList>
            <p class="wd-hint">
                Recipient addresses, message content, links and provider receipts are not retained for diagnostics.
            </p>
        </ContentSection>
        <ResolutionForm v-if="open && canResolve" :notice="notice" />
        <ContentSection v-else-if="open" title="Record a resolution">
            <UnavailableNotice
                title="Recording a resolution is outside your capabilities"
                reason="You can review this delivery. Recording its outcome needs the notification resolution capability."
            />
        </ContentSection>
    </AdminShell>
</template>
