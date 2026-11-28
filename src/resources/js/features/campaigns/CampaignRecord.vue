<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Clock, Gift, Lock, Plus } from '@lucide/vue';
import Breadcrumbs from '../../components/Breadcrumbs.vue';
import ContentSection from '../../components/ContentSection.vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import FormField from '../../components/FormField.vue';
import RecordHeader from '../../components/RecordHeader.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { dateOnly, dateTime } from '../admin/format';
import FormDialog from '../admin/FormDialog.vue';
import StageBadge from '../brand-leads/StageBadge.vue';
import StageSummary from '../brand-leads/StageSummary.vue';
import {
    awardStates,
    daysBetween,
    incentiveStates,
    LATE_LINK_DAYS,
    offerReadiness,
    previewReadiness,
} from '../incentives/format';
import OfferCard from '../incentives/OfferCard.vue';
import type { AwardState, Offer, PartnerOffer } from '../incentives/types';
import { stageLabel } from '../portals/activity';
import type { StageOption } from '../portals/types';
import { calendarDate, campaignStatuses, dueHint } from './format';
import type { CampaignDetail, CampaignLead } from './types';

const props = defineProps<{
    campaign: CampaignDetail;
    brand: boolean;
    canManage: boolean;
    leads: (CampaignLead & { award?: AwardState | null })[];
    incentive: Offer | PartnerOffer | null;
    stages: StageOption[];
}>();

const status = computed(() => campaignStatuses[props.campaign.status]);
const due = computed(() => (props.campaign.due_date ? calendarDate(props.campaign.due_date) : 'Not set'));
// The due-date hint only helps while the brief is live.
const hint = computed(() => (props.campaign.status === 'shared' ? dueHint(props.campaign.due_date) : ''));
const label = (stage: string) => stageLabel(props.stages, stage);
const live = computed(() => props.campaign.status === 'shared');

const columns = computed<DataColumn[]>(() => [
    { key: 'display_name', label: 'Lead' },
    { key: 'stage', label: 'Stage' },
    { key: 'registered_at', label: 'Registered' },
    { key: 'linked_at', label: 'Linked to campaign' },
    ...(!props.brand && props.incentive ? [{ key: 'award', label: 'Award' }] : []),
]);
const late = (lead: CampaignLead) => daysBetween(lead.registered_at, lead.linked_at);

// The Brand's offer card or the Partner's own view of it.
const brandOffer = computed(() => (props.brand ? (props.incentive as Offer | null) : null));
const partnerOffer = computed(() => (props.brand ? null : (props.incentive as PartnerOffer | null)));
// The Brand sees the card its Partner sees; per-Partner counts belong to the Partner, so they start at zero.
const brandCard = computed(() =>
    brandOffer.value ? { ...brandOffer.value, brand_name: props.campaign.brand_name ?? '', granted: 0 } : null,
);
// Claims are made on the Incentives page, where the claim dialog lists the qualifying leads.
function claim(): void {
    if (partnerOffer.value) router.visit(`/partner/incentives#offer-${partnerOffer.value.id}`);
}

// "Add incentive" creates a draft offer already tied to this brief and its Partner.
const dialog = ref<InstanceType<typeof FormDialog>>();
const create = useForm({ title: '', campaign_id: props.campaign.id });
function submit(): void {
    if (!create.processing) create.post('/brand/incentives');
}
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
                <dd>
                    <Link class="wd-link" :href="`/brand/partners/${campaign.partnership_id}`">{{
                        campaign.creator_name
                    }}</Link>
                </dd>
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
        <template v-if="live" #actions>
            <template v-if="brand">
                <Link class="wd-button wd-button-secondary" :href="`/brand/lead-registrations?campaign=${campaign.id}`"
                    >Open in Lead registrations</Link
                >
                <Button
                    v-if="canManage && !campaign.incentive"
                    type="button"
                    class="wd-button wd-button-primary"
                    @click="dialog?.open($event.currentTarget as HTMLElement)"
                    ><Gift :size="16" aria-hidden="true" />Add incentive…</Button
                >
            </template>
            <Link
                v-else-if="canManage"
                class="wd-button wd-button-primary"
                :href="`/partner/brand-sharing?campaign=${campaign.id}`"
                ><Plus :size="16" aria-hidden="true" />Register a lead for this brief</Link
            >
        </template>
        <p v-if="campaign.status === 'ended'" class="wd-warning cp-notice" role="note">
            The relationship with this Partner has ended. They can no longer read this brief, and their registered leads
            are hidden. Approved incentive awards are kept. It stays here as your record.
        </p>
    </RecordHeader>

    <div class="wd-record-columns" :class="{ 'cp-incentive-first': partnerOffer }">
        <div class="wd-record-main">
            <ContentSection
                v-if="campaign.status !== 'draft'"
                :title="brand ? 'Registered leads' : 'Your leads for this brief'"
                heading-id="leads-heading"
            >
                <template #header>
                    <header class="cp-section-head">
                        <h2 id="leads-heading">
                            {{ brand ? 'Registered leads' : 'Your leads for this brief'
                            }}<span class="cp-count">{{ leads.length }}</span>
                        </h2>
                        <p class="cp-owner">Leads with this brief as their source campaign</p>
                    </header>
                </template>
                <template v-if="leads.length">
                    <StageSummary :stages="leads.map((lead) => lead.stage)" :label="label" />
                    <DataTable
                        :caption="`${brand ? 'Registered leads' : 'Your leads'} for ${campaign.title}`"
                        :columns="columns"
                        :rows="leads"
                        :row-key="(row: CampaignLead) => row.id"
                    >
                        <template #cell-display_name="{ row }">
                            <Link
                                v-if="brand"
                                class="wd-link"
                                :href="`/brand/lead-registrations/${row.id}?campaign=${campaign.id}`"
                                >{{ row.display_name }}</Link
                            >
                            <template v-else>{{ row.display_name }}</template>
                        </template>
                        <template #cell-stage="{ row }"
                            ><StageBadge :stage="row.stage" :label="label(row.stage)"
                        /></template>
                        <template #cell-registered_at="{ row }">
                            <time :datetime="row.registered_at" :title="dateTime(row.registered_at)">{{
                                dateOnly(row.registered_at)
                            }}</time>
                        </template>
                        <template #cell-linked_at="{ row }">
                            <time :datetime="row.linked_at" :title="dateTime(row.linked_at)">{{
                                dateOnly(row.linked_at)
                            }}</time>
                            <span v-if="late(row) > LATE_LINK_DAYS" class="ic-flag"
                                ><Clock :size="12" aria-hidden="true" />Linked {{ late(row) }} days after
                                registering</span
                            >
                        </template>
                        <template #cell-award="{ row }">
                            <StatusBadge
                                v-if="row.award"
                                :label="awardStates[row.award as AwardState].label"
                                :tone="awardStates[row.award as AwardState].tone"
                            />
                            <span v-else class="wd-hint">—</span>
                        </template>
                    </DataTable>
                </template>
                <EmptyState
                    v-else
                    :title="brand ? 'No leads linked yet' : 'No leads registered for this brief'"
                    :description="
                        brand
                            ? 'When the Partner registers a lead with this brief as its source campaign, it appears here with its stage.'
                            : `Register a lead with ${campaign.brand_name} and choose this brief as its source campaign. ${campaign.brand_name} sees it on the campaign.`
                    "
                />
            </ContentSection>

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
        </div>

        <aside v-if="brand && campaign.status !== 'draft'" class="wd-record-aside" aria-label="Incentive">
            <ContentSection title="Incentive" heading-id="incentive-heading">
                <div v-if="brandOffer && brandCard" class="ic-preview">
                    <p class="ic-panel-meta">
                        <StatusBadge
                            :label="incentiveStates[brandOffer.state].label"
                            :tone="incentiveStates[brandOffer.state].tone"
                        />
                        <Link class="wd-link" :href="`/brand/incentives/${brandOffer.id}`">Open incentive</Link>
                    </p>
                    <OfferCard
                        :offer="brandCard"
                        :readiness="previewReadiness(brandOffer.state)"
                        preview
                        terms-placeholder="No terms yet."
                        :stage-label="label"
                    />
                    <p class="ic-small ic-muted">
                        {{ brandOffer.granted }} {{ brandOffer.granted === 1 ? 'award' : 'awards' }} approved ·
                        {{ brandOffer.pending ? `${brandOffer.pending} pending` : 'no claims waiting' }}
                    </p>
                </div>
                <EmptyState
                    v-else
                    title="No incentive on this brief"
                    description="Add a reward for leads registered with this brief. The Partner claims awards and you approve each one."
                >
                    <Button
                        v-if="canManage && live"
                        type="button"
                        class="wd-button wd-button-secondary cp-empty-action"
                        @click="dialog?.open($event.currentTarget as HTMLElement)"
                        ><Gift :size="16" aria-hidden="true" />Add incentive…</Button
                    >
                </EmptyState>
            </ContentSection>
        </aside>
        <aside v-else-if="partnerOffer" class="wd-record-aside" aria-label="Incentive">
            <ContentSection title="Incentive on this brief" heading-id="incentive-heading">
                <OfferCard
                    :offer="partnerOffer"
                    :readiness="offerReadiness(partnerOffer)"
                    :can-claim="canManage"
                    :stage-label="label"
                    @claim="claim"
                />
            </ContentSection>
        </aside>
    </div>

    <FormDialog
        v-if="brand && canManage"
        id="new-incentive"
        ref="dialog"
        title="Add incentive"
        :description="`A private draft linked to this brief. Only ${campaign.creator_name ?? 'its Partner'} can receive it, and only leads with this brief as their source campaign count.`"
        submit-label="Create draft"
        :processing="create.processing"
        :errors="create.errors"
        @submit="submit"
    >
        <FormField
            id="incentive-title"
            v-model="create.title"
            label="Incentive name"
            :maxlength="160"
            :error="create.errors.title || create.errors.campaign_id"
        />
    </FormDialog>
</template>
