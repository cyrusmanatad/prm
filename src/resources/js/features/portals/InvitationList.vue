<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import FilterChips, { type FilterChip } from '../../components/FilterChips.vue';
import StatusBadge, { type BadgeTone } from '../../components/StatusBadge.vue';
import { confirmAction } from '../../composables/useConfirmation';
import { emailStates } from '../notifications/emailStates';
import { dateTime } from '../admin/format';
import type { PartnerInvitation } from './types';

const props = defineProps<{ invitations: PartnerInvitation[] }>();

/** Rows shown before "Show all", so finished invitations never push the hub down. */
const PREVIEW = 5;
const statuses: { value: PartnerInvitation['status']; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'expired', label: 'Expired' },
    { value: 'revoked', label: 'Revoked' },
];
/** Short delivery label and tone; the full wording stays available as text for assistive technology and as a tooltip. */
const deliveries: Record<string, { label: string; tone: BadgeTone }> = {
    pending: { label: 'Email queued', tone: 'neutral' },
    sending: { label: 'Email sending', tone: 'neutral' },
    sent: { label: 'Email sent', tone: 'success' },
    captured: { label: 'Captured locally', tone: 'neutral' },
    cancelled: { label: 'Email cancelled', tone: 'neutral' },
    uncertain: { label: 'Delivery uncertain', tone: 'warning' },
};
const linkOnly = 'Link only; email not requested';

const current = ref<string>('pending');
const expanded = ref(false);
const refreshing = ref(false);
watch(current, () => (expanded.value = false));

const chips = computed<FilterChip[]>(() => [
    ...statuses.map((status) => ({
        ...status,
        count: props.invitations.filter((row) => row.status === status.value).length,
    })),
    { value: '', label: 'All', count: props.invitations.length },
]);
const matching = computed(() =>
    current.value ? props.invitations.filter((row) => row.status === current.value) : props.invitations,
);
const visible = computed(() => (expanded.value ? matching.value : matching.value.slice(0, PREVIEW)));
const currentLabel = computed(() => statuses.find((status) => status.value === current.value)?.label.toLowerCase());
const summary = computed(() => {
    const noun = current.value ? `${currentLabel.value} ` : '';
    return `Showing ${visible.value.length} of ${matching.value.length} ${noun}${matching.value.length === 1 ? 'invitation' : 'invitations'}`;
});
const columns = computed<DataColumn[]>(() => [
    { key: 'recipient_email', label: 'Recipient' },
    ...(current.value ? [] : [{ key: 'status', label: 'Status' }]),
    { key: 'created_at', label: 'Sent' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'email_state', label: 'Delivery' },
    { key: 'action', label: 'Action' },
]);

function delivery(row: PartnerInvitation) {
    const state = row.email_state ? deliveries[row.email_state] : null;
    return {
        label: state?.label ?? (row.email_state ? (emailStates[row.email_state] ?? row.email_state) : 'Link only'),
        tone: state?.tone ?? 'neutral',
        detail: row.email_state ? (emailStates[row.email_state] ?? row.email_state) : linkOnly,
    };
}
/** Short date for a list row, with the year only when it is not the current one. */
function shortDate(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return dateTime(value);
    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
    });
}
/** Days left on a pending invitation, counted in calendar days. */
function daysLeft(value: string, now = new Date()): number {
    const day = (date: Date) => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    return Math.round((day(new Date(value)) - day(now)) / 86_400_000);
}
function expiry(row: PartnerInvitation): { text: string; soon: boolean } {
    if (row.status !== 'pending') return { text: shortDate(row.expires_at), soon: false };
    const days = daysLeft(row.expires_at);
    if (days <= 0) return { text: 'today', soon: true };
    if (days === 1) return { text: 'tomorrow', soon: true };
    return { text: `in ${days} days`, soon: false };
}
function revoke(row: PartnerInvitation, event: Event): void {
    confirmAction(
        'Revoke invitation',
        `Revoke the invitation to ${row.recipient_email}? They will no longer be able to accept it. Pending email delivery will be cancelled.`,
        () => router.delete(`/partner-invitations/${row.id}`, { preserveScroll: true }),
        event,
        { tone: 'danger' },
    );
}
function refresh(): void {
    router.reload({
        only: ['invitations'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}
</script>

<template>
    <section class="wd-invitations rounded-2xl border border-line bg-white p-5" aria-labelledby="invitations-title">
        <header class="wd-invitations-head">
            <div class="wd-invitations-title">
                <h2 id="invitations-title" class="font-display text-[18px] font-bold">Invitations</h2>
                <span>{{ invitations.length }} total</span>
            </div>
            <button
                type="button"
                class="wd-table-button wd-invitations-refresh"
                :disabled="refreshing"
                aria-describedby="invitations-refresh-hint"
                @click="refresh"
            >
                <RefreshCw :size="15" aria-hidden="true" /><span>Refresh status</span>
            </button>
            <span id="invitations-refresh-hint" class="wd-sr-only">Checks email delivery progress</span>
        </header>
        <FilterChips
            label="Filter invitations by status"
            compact
            :chips="chips"
            :current="current"
            @select="(value) => (current = value)"
        />
        <p class="wd-sr-only" role="status">{{ refreshing ? 'Refreshing invitation status…' : '' }}</p>
        <DataTable
            v-if="matching.length"
            class="wd-invitations-table"
            caption="Invitations"
            :columns="columns"
            :rows="visible"
            :row-key="(row) => row.id"
        >
            <template #head-action><span class="wd-sr-only">Action</span></template>
            <template #cell-status="{ row }">{{
                statuses.find((status) => status.value === row.status)?.label
            }}</template>
            <template #cell-created_at="{ row }">
                <time :datetime="row.created_at" v-bind="{ title: dateTime(row.created_at) }">{{
                    shortDate(row.created_at)
                }}</time>
            </template>
            <template #cell-expires_at="{ row }">
                <time
                    :datetime="row.expires_at"
                    :class="{ 'is-soon': expiry(row).soon }"
                    v-bind="{ title: dateTime(row.expires_at) }"
                    >{{ expiry(row).text }}</time
                >
            </template>
            <template #cell-email_state="{ row }">
                <span v-bind="{ title: delivery(row).detail }">
                    <StatusBadge :label="delivery(row).label" :tone="delivery(row).tone" />
                    <span v-if="delivery(row).detail !== delivery(row).label" class="wd-sr-only"
                        >: {{ delivery(row).detail }}</span
                    >
                </span>
            </template>
            <template #cell-action="{ row }">
                <button
                    v-if="row.status === 'pending'"
                    type="button"
                    class="wd-action-danger wd-invitations-revoke"
                    v-bind="{ 'aria-label': `Revoke invitation to ${row.recipient_email}` }"
                    @click="revoke(row, $event)"
                >
                    Revoke
                </button>
            </template>
        </DataTable>
        <p v-else class="wd-invitations-empty">
            {{ current ? `No ${currentLabel} invitations.` : 'No invitations yet.' }}
        </p>
        <div v-if="matching.length" class="wd-invitations-foot">
            <span>{{ summary }}</span>
            <button
                v-if="matching.length > PREVIEW"
                type="button"
                class="wd-link"
                :aria-expanded="expanded ? 'true' : 'false'"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show all ${matching.length}` }}
            </button>
        </div>
    </section>
</template>
