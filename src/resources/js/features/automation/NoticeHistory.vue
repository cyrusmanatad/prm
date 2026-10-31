<script setup lang="ts">
import ContentSection from '../../components/ContentSection.vue';

export type Notice = { id: string; kind: string; state: string; created_at: string };
defineProps<{ notices: Notice[] }>();
const states: Record<string, string> = {
    pending: 'Queued for email; delivery pending',
    sending: 'Send reserved; outcome pending',
    sent: 'Accepted by mail transport; receipt unconfirmed',
    captured: 'Captured locally; no external email sent',
    cancelled: 'Cancelled before sending',
    uncertain: 'Delivery uncertain; automatic resend blocked',
};
</script>

<template>
    <ContentSection
        title="Your scheduled email history"
        description="Your latest 50 notices in this workspace. A queued email is not proof of delivery. Refresh to check the latest status."
    >
        <p v-if="!notices.length" class="wd-empty">No scheduled email notices yet.</p>
        <article v-for="notice in notices" :key="notice.id" class="wd-section-stack">
            <h3>{{ notice.kind === 'schedule.reminder' ? 'Reminder email' : 'Weekly review email' }}</h3>
            <p>{{ states[notice.state] ?? notice.state }}</p>
            <p class="wd-hint">{{ new Date(notice.created_at).toLocaleString() }}</p>
        </article>
        <p class="wd-hint">
            Withdraw consent using the saved schedule or disable a notice in preferences. A send already reserved may
            still arrive.
        </p>
    </ContentSection>
</template>
