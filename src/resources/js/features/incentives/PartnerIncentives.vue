<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Award as AwardIcon, Check, Info, Lock } from '@lucide/vue';
import ContentSection from '../../components/ContentSection.vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import FieldGroup from '../../components/FieldGroup.vue';
import FigureList, { type Figure } from '../../components/FigureList.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { dateOnly } from '../admin/format';
import FormDialog from '../admin/FormDialog.vue';
import StageBadge from '../brand-leads/StageBadge.vue';
import { calendarDate } from '../campaigns/format';
import CapMeter from './CapMeter.vue';
import { awardStates, cancelReasons, candidateReasons, rewardValue } from './format';
import RewardBlock from './RewardBlock.vue';
import RuleList from './RuleList.vue';
import type { PartnerAward, PartnerOffer } from './types';

const props = defineProps<{
    canWrite: boolean;
    offers: PartnerOffer[];
    awards: PartnerAward[];
    figures: { offers: number; review: number; approved: number; received: number };
    stageLabel: (stage: string) => string;
}>();

const figureList = computed<Figure[]>(() => [
    { label: 'Available offers', value: props.figures.offers, scope: 'Open for claims now' },
    { label: 'Claims in review', value: props.figures.review, scope: 'The Brand approves each one' },
    { label: 'Approved, awaiting delivery', value: props.figures.approved, scope: 'Delivered outside WeavDash' },
    { label: 'Received', value: props.figures.received, scope: 'Marked fulfilled by the Brand' },
]);

type Readiness = { ok: boolean; title: string; detail: string; progress: number | null };
const plural = (count: number, one: string) => `${count} ${count === 1 ? one : `${one}s`}`;
function readiness(offer: PartnerOffer): Readiness {
    const need = offer.leads_per_award;
    const ready = Math.min(offer.ready, need);
    if (offer.state === 'ended')
        return { ok: false, title: 'This offer has ended', detail: 'Awards stay below', progress: null };
    if (!offer.open)
        return {
            ok: false,
            title: `Claims open ${calendarDate(offer.starts_on)}`,
            detail: 'Not open yet',
            progress: null,
        };
    if (!offer.has_room)
        return { ok: false, title: 'No awards left on this offer', detail: 'Offer limit reached', progress: null };
    if (offer.cap_per_partner !== null && offer.granted + offer.pending >= offer.cap_per_partner)
        return {
            ok: false,
            title: 'Your award limit is reached',
            detail: `${offer.granted + offer.pending} of ${offer.cap_per_partner} used`,
            progress: null,
        };
    if (offer.ready < need)
        return {
            ok: false,
            title: `Needs ${plural(need, 'qualifying lead')}`,
            detail: `${offer.ready} of ${need} ready`,
            progress: ready / need,
        };
    return { ok: true, title: `${plural(offer.ready, 'lead')} ready`, detail: 'Ready to claim', progress: 1 };
}
const offerSummary = computed(() => {
    const brands = [...new Set(props.offers.map((offer) => offer.brand_name))];
    const from = brands.length === 1 ? brands[0] : `${brands.length} Brands`;
    return `${plural(props.offers.length, 'incentive')} from ${from}`;
});

// Claim dialog: exactly `leads_per_award` eligible leads and an optional note.
const dialog = ref<InstanceType<typeof FormDialog>>();
const claiming = ref<PartnerOffer | null>(null);
const claim = useForm({ partnership: '', registrations: [] as string[], note: '' });
function openClaim(offer: PartnerOffer, event: Event): void {
    claiming.value = offer;
    claim.reset();
    claim.clearErrors();
    claim.partnership = offer.partnership_id;
    dialog.value?.open(event.currentTarget as HTMLElement);
}
const need = computed(() => claiming.value?.leads_per_award ?? 1);
function submitClaim(): void {
    if (!claiming.value || claim.processing) return;
    if (claim.registrations.length !== need.value) {
        claim.setError(
            'registrations',
            `Choose ${need.value} ${need.value === 1 ? 'lead' : 'leads'} that meet the rule.`,
        );
        return;
    }
    claim.post(`/partner/incentives/${claiming.value.id}/claims`, {
        preserveScroll: true,
        onSuccess: () => dialog.value?.close(),
    });
}

const columns: DataColumn[] = [
    { key: 'incentive', label: 'Incentive' },
    { key: 'reward', label: 'Reward' },
    { key: 'state', label: 'Status' },
    { key: 'detail', label: 'Details' },
];
function detail(award: PartnerAward): string {
    if (award.state === 'fulfilled')
        return `Delivered ${calendarDate(award.fulfilled_on)}${award.fulfilment_reference ? ` · ${award.fulfilment_reference}` : ''}`;
    if (award.state === 'approved')
        return `Approved ${award.decided_at ? dateOnly(award.decided_at) : ''} · awaiting delivery`;
    if (award.state === 'rejected') return award.decision_reason ?? 'Rejected';
    if (award.state === 'cancelled') return award.cancel_reason ? cancelReasons[award.cancel_reason] : 'Cancelled';
    return award.decision_reason ? `Back in review: ${award.decision_reason}` : `Claimed ${dateOnly(award.claimed_at)}`;
}
</script>

<template>
    <header class="cp-head">
        <div>
            <h1>Incentives</h1>
            <p class="wd-description">
                Rewards Brands offer your workspace. Claim an award when your registered leads qualify. The Brand
                approves it and delivers the reward outside WeavDash.
            </p>
        </div>
    </header>

    <ContentSection v-if="!offers.length && !awards.length" title="Offers" heading-id="offers-heading">
        <EmptyState
            title="No incentives yet"
            description="When a connected Brand offers you a reward, it appears here with what qualifies."
        />
    </ContentSection>
    <template v-else>
        <FigureList label="Incentive summary" :figures="figureList" />
        <section class="ic-offers-section" aria-labelledby="offers-heading">
            <header class="ic-offers-head">
                <h2 id="offers-heading">Offers</h2>
                <p v-if="offers.length" class="ic-small ic-muted">{{ offerSummary }}</p>
            </header>
            <div v-if="offers.length" class="ic-offers">
                <article
                    v-for="offer in offers"
                    :id="`offer-${offer.id}`"
                    :key="offer.id"
                    class="wd-card ic-offer"
                    :aria-labelledby="`offer-title-${offer.id}`"
                >
                    <header class="ic-offer-head">
                        <div>
                            <p class="ic-offer-from">
                                From {{ offer.brand_name }}
                                <template v-if="offer.campaign_id">
                                    ·
                                    <Link class="wd-link" :href="`/partner/campaigns/${offer.campaign_id}`">{{
                                        offer.campaign_title
                                    }}</Link>
                                </template>
                            </p>
                            <h3 :id="`offer-title-${offer.id}`" class="ic-card-title">
                                {{ offer.title }}
                                <StatusBadge v-if="offer.state === 'ended'" label="Ended" tone="neutral" />
                            </h3>
                        </div>
                        <Button
                            v-if="canWrite && offer.state === 'shared'"
                            type="button"
                            class="wd-button wd-button-primary ic-claim"
                            :disabled="!readiness(offer).ok"
                            :ariaDescribedby="`offer-ready-${offer.id}`"
                            @click="openClaim(offer, $event)"
                            ><component
                                :is="readiness(offer).ok ? AwardIcon : Lock"
                                :size="16"
                                aria-hidden="true"
                            />Claim award…</Button
                        >
                    </header>
                    <RewardBlock :terms="offer" />
                    <RuleList :terms="offer" :stage-label="stageLabel" />
                    <CapMeter :used="offer.granted" :cap="offer.cap_per_partner" label="Your awards approved" />
                    <p v-if="offer.terms" class="ic-small ic-terms">{{ offer.terms }}</p>
                    <footer class="ic-ready" :class="{ 'is-ok': readiness(offer).ok }">
                        <p :id="`offer-ready-${offer.id}`" class="ic-ready-text">
                            <strong>{{ readiness(offer).title }}</strong
                            ><span class="ic-muted">{{ readiness(offer).detail }}</span>
                        </p>
                        <div
                            v-if="readiness(offer).progress !== null"
                            class="ic-ready-bar"
                            role="progressbar"
                            :aria-label="`Qualifying leads ready for ${offer.title}`"
                            aria-valuemin="0"
                            :aria-valuemax="offer.leads_per_award"
                            :aria-valuenow="Math.min(offer.ready, offer.leads_per_award)"
                        >
                            <span :style="{ width: `${readiness(offer).progress! * 100}%` }"></span>
                        </div>
                    </footer>
                </article>
            </div>
            <div v-else class="wd-card">
                <EmptyState title="No current offers" description="Ended offers keep their awards below." />
            </div>
        </section>

        <ContentSection title="Your awards" heading-id="awards-heading">
            <template #header>
                <header class="cp-section-head">
                    <h2 id="awards-heading">
                        Your awards<span class="cp-count">{{ awards.length }}</span>
                    </h2>
                </header>
            </template>
            <DataTable
                v-if="awards.length"
                caption="Your awards"
                :columns="columns"
                :rows="awards"
                :row-key="(row: PartnerAward) => row.id"
            >
                <template #cell-incentive="{ row }">
                    <span class="cp-title">
                        <span>{{ row.incentive_title }}</span>
                        <span class="cp-sub">{{ row.leads.join(', ') }}</span>
                    </span>
                </template>
                <template #cell-reward="{ row }">{{ rewardValue(row) }}</template>
                <template #cell-state="{ row }">
                    <StatusBadge :label="awardStates[row.state].label" :tone="awardStates[row.state].tone" />
                </template>
                <template #cell-detail="{ row }">{{ detail(row) }}</template>
            </DataTable>
            <EmptyState
                v-else
                title="No awards yet"
                description="Claim an award from an offer above when your leads qualify."
            />
        </ContentSection>
    </template>

    <FormDialog
        v-if="canWrite"
        id="claim-award"
        ref="dialog"
        title="Claim award"
        :description="claiming ? `${claiming.title} · from ${claiming.brand_name}` : undefined"
        submit-label="Submit claim"
        :processing="claim.processing"
        :errors="claim.errors"
        @submit="submitClaim"
    >
        <template v-if="claiming">
            <RewardBlock :terms="claiming" />
            <fieldset class="ic-claim-leads" aria-describedby="claim-count">
                <legend class="ic-legend">Choose {{ need }} qualifying {{ need === 1 ? 'lead' : 'leads' }}</legend>
                <p id="claim-count" class="ic-small ic-muted" aria-live="polite">
                    {{ claim.registrations.length }} of {{ need }} selected
                </p>
                <ul class="ic-checklist">
                    <li v-for="lead in claiming.candidates" :key="lead.id">
                        <label class="ic-check">
                            <input
                                v-model="claim.registrations"
                                type="checkbox"
                                :value="lead.id"
                                :aria-invalid="claim.errors.registrations ? 'true' : undefined"
                                :disabled="
                                    !lead.eligible ||
                                    (!claim.registrations.includes(lead.id) && claim.registrations.length >= need)
                                "
                            />
                            <span class="ic-check-body">
                                <span class="ic-check-top">
                                    <span class="ic-check-name">{{ lead.name }}</span>
                                    <StageBadge :stage="lead.stage" :label="stageLabel(lead.stage)" />
                                </span>
                                <span v-if="lead.campaign_title" class="ic-small ic-muted"
                                    >Source: {{ lead.campaign_title }}</span
                                >
                                <span class="ic-check-reason" :class="{ 'is-ok': lead.eligible }">
                                    <component :is="lead.eligible ? Check : Info" :size="14" aria-hidden="true" />{{
                                        lead.eligible
                                            ? `Meets the rule · ${stageLabel(lead.stage)}`
                                            : candidateReasons[lead.reason!]
                                    }}
                                </span>
                            </span>
                        </label>
                    </li>
                </ul>
                <p v-if="!claiming.candidates.length" class="wd-hint">
                    You have no active registrations with {{ claiming.brand_name }} yet.
                </p>
                <p v-if="claim.errors.registrations" class="wd-field-error" role="alert">
                    {{ claim.errors.registrations }}
                </p>
            </fieldset>
            <FieldGroup
                id="claim-note"
                :label="`Note to ${claiming.brand_name} (optional)`"
                :error="claim.errors.note"
                v-slot="{ id, describedby, invalid }"
            >
                <textarea
                    :id="id"
                    v-model="claim.note"
                    rows="3"
                    maxlength="1000"
                    :aria-describedby="describedby"
                    :aria-invalid="invalid"
                ></textarea>
            </FieldGroup>
            <p class="cp-note">
                <Info :size="14" aria-hidden="true" /><span
                    >{{ claiming.brand_name }} reviews every claim and delivers approved rewards outside WeavDash.</span
                >
            </p>
        </template>
    </FormDialog>
</template>
