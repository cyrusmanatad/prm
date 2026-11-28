<script setup lang="ts">
import { computed } from 'vue';
import DetailList from '../../components/DetailList.vue';
const props = defineProps<{ counts: Record<string, number> }>();
const labels: Record<string, string> = {
    memberships: 'Workspace memberships',
    sessions: 'Account sessions',
    pipeline_entries: 'Leads',
    brand_contacts: 'Contacts',
    reminders: 'Reminders',
    outreach_templates: 'Outreach templates',
    onboarding_requests: 'Onboarding requests',
    onboarding_documents: 'Documents',
    onboarding_submissions: 'Submission history',
    partnerships: 'Partner relationships',
    lead_registrations: 'Shared lead registrations',
    campaigns: 'Campaign briefs',
    media_kits: 'Media kits',
    lead_links: 'Lead links',
    partner_join_links: 'Sign-up links',
    business_schedules: 'Schedules and rules',
    follow_up_drafts: 'Private drafts',
    notification_outbox: 'Notification history',
    account_notification_outbox: 'Account notification history',
    invoice_drafts: 'Invoice drafts',
    manual_payments: 'Payment records',
    audit_logs: 'Audit history',
    privacy_exports: 'Private export receipts',
};
const items = computed(() =>
    Object.entries(labels)
        .filter(([key]) => (props.counts[key] ?? 0) > 0)
        .map(([key, label]) => ({ key, label, value: props.counts[key] ?? 0 })),
);
</script>
<template>
    <details>
        <summary class="wd-link">Affected records</summary>
        <p class="wd-description">
            Counts include saved history. Eligible pending work is cancelled; records and documents are kept for review.
        </p>
        <DetailList v-if="items.length" :items="items" />
        <p v-else class="wd-description">No records in these categories.</p>
    </details>
</template>
