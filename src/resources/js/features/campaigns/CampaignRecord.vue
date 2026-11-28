<script setup lang="ts">
import { computed } from 'vue';
import { Lock } from '@lucide/vue';
import Breadcrumbs from '../../components/Breadcrumbs.vue';
import ContentSection from '../../components/ContentSection.vue';
import RecordHeader from '../../components/RecordHeader.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateOnly, dateTime } from '../admin/format';
import { calendarDate, campaignStatuses, dueHint } from './format';
import type { CampaignDetail } from './types';

const props = defineProps<{ campaign: CampaignDetail; brand: boolean }>();

const status = computed(() => campaignStatuses[props.campaign.status]);
const due = computed(() => (props.campaign.due_date ? calendarDate(props.campaign.due_date) : 'Not set'));
// The due-date hint only helps while the brief is live.
const hint = computed(() => (props.campaign.status === 'shared' ? dueHint(props.campaign.due_date) : ''));
</script>

<template>
    <Breadcrumbs
        :items="[
            { label: 'Campaigns', href: brand ? '/brand/campaigns' : '/partner/campaigns' },
            { label: campaign.title },
        ]"
    />
    <RecordHeader
        :eyebrow="brand ? 'Campaign' : `Brief from ${campaign.brand_name}`"
        :title="campaign.title"
        heading-id="campaign-title"
    >
        <template #meta>
            <div v-if="brand">
                <dt class="wd-sr-only">Status</dt>
                <dd><StatusBadge :label="status.label" :tone="status.tone" /></dd>
            </div>
            <div v-if="brand && campaign.creator_name">
                <dt>Partner</dt>
                <dd>{{ campaign.creator_name }}</dd>
            </div>
            <div>
                <dt>Due</dt>
                <dd>
                    <time v-if="campaign.due_date" :datetime="campaign.due_date">{{ due }}</time
                    ><template v-else>{{ due }}</template
                    ><span v-if="hint" class="cp-meta-hint"> ({{ hint.toLowerCase() }})</span>
                </dd>
            </div>
            <div v-if="campaign.shared_at">
                <dt>Shared</dt>
                <dd>
                    <time :datetime="campaign.shared_at" :title="dateTime(campaign.shared_at)">{{
                        dateOnly(campaign.shared_at)
                    }}</time>
                </dd>
            </div>
        </template>
        <p v-if="campaign.status === 'ended'" class="wd-warning cp-notice" role="note">
            The relationship with this Partner has ended. They can no longer read this brief. It stays here as your
            record.
        </p>
    </RecordHeader>

    <ContentSection title="Brief" heading-id="brief-heading">
        <template #header>
            <header class="cp-section-head">
                <h2 id="brief-heading">Brief</h2>
                <p v-if="campaign.status !== 'draft'" class="cp-owner">
                    <Lock :size="14" aria-hidden="true" />Read-only<template v-if="campaign.shared_at">
                        since {{ dateOnly(campaign.shared_at) }}</template
                    >
                </p>
                <p v-else class="cp-owner">
                    <Lock :size="14" aria-hidden="true" />Private draft · only owners and admins can edit
                </p>
            </header>
        </template>
        <p v-if="campaign.brief" class="cp-brief">{{ campaign.brief }}</p>
        <p v-else class="wd-hint">No brief yet.</p>
    </ContentSection>
</template>
