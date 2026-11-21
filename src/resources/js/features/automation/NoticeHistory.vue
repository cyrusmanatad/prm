<script setup lang="ts">
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusBadge, { type BadgeTone } from '../../components/StatusBadge.vue';

export type Notice = {
    id: string;
    kind: string;
    state: string;
    created_at: string;
    available_at?: string;
    failure_code?: string | null;
};
defineProps<{ notices: Notice[] }>();
const states: Record<string, [string, BadgeTone]> = {
    pending: ['Queued for email; delivery pending', 'neutral'],
    sending: ['Send reserved; outcome pending', 'neutral'],
    sent: ['Accepted by mail transport; receipt unconfirmed', 'success'],
    captured: ['Captured locally; no external email sent', 'neutral'],
    cancelled: ['Cancelled before sending', 'neutral'],
    uncertain: ['Delivery uncertain; automatic resend blocked', 'warning'],
};
const kinds: Record<string, string> = {
    'schedule.reminder': 'Reminder email',
    'follow_up.outreach': 'Approved follow-up email',
    'follow_up.review': 'Follow-up review email',
};
const columns: DataColumn[] = [
    { key: 'kind', label: 'Email' },
    { key: 'state', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];
const created = (value: string) =>
    new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <section class="rec-card" aria-labelledby="history-heading">
        <header class="rec-card-head">
            <h2 id="history-heading">Email history</h2>
            <span class="rec-count">{{ notices.length }}</span>
            <p class="rec-note">Your latest 50 · a queued email is not proof of delivery</p>
        </header>
        <DataTable
            v-if="notices.length"
            class="rec-table rec-history-table"
            caption="Email history"
            :columns="columns"
            :rows="notices"
            :row-key="(notice) => notice.id"
        >
            <template #cell-kind="{ row }"
                ><span class="rec-row-name">{{ kinds[row.kind] ?? 'Weekly review email' }}</span></template
            >
            <template #cell-state="{ row }">
                <StatusBadge :label="states[row.state]?.[0] ?? row.state" :tone="states[row.state]?.[1] ?? 'neutral'" />
                <span v-if="row.state === 'pending' && row.failure_code" class="rec-row-sub"
                    >A previous attempt was deferred. Consent and access are checked again before sending.</span
                >
            </template>
            <template #cell-created_at="{ row }"
                ><time :datetime="row.created_at" :title="new Date(row.created_at).toLocaleString()">{{
                    created(row.created_at)
                }}</time></template
            >
        </DataTable>
        <EmptyState
            v-else
            title="No scheduled emails yet"
            description="Reminder, digest and follow-up emails appear here."
        />
        <footer class="rec-card-foot">
            Refresh to check the latest status. Withdraw consent on a schedule or in Preferences; a send already
            reserved may still arrive.
        </footer>
    </section>
</template>
