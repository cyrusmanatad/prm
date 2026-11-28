<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Award as AwardIcon, Lock } from '@lucide/vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import CapMeter from './CapMeter.vue';
import type { OfferReadiness } from './format';
import RewardBlock from './RewardBlock.vue';
import RuleList from './RuleList.vue';
import type { IncentiveState, IncentiveTerms } from './types';

/**
 * One incentive offer as a Partner sees it. The Brand's form shows the same card as a preview: then the Claim button
 * is inert and hidden from assistive technology, the campaign is plain text, and empty terms show a placeholder.
 */
const props = defineProps<{
    offer: IncentiveTerms & {
        id: string;
        state: IncentiveState;
        brand_name: string;
        campaign_id: string | null;
        campaign_title: string | null;
        cap_per_partner: number | null;
        granted: number;
    };
    readiness: OfferReadiness;
    canClaim?: boolean;
    preview?: boolean;
    termsPlaceholder?: string;
    stageLabel: (stage: string) => string;
}>();
defineEmits<{ claim: [event: MouseEvent] }>();
const readyId = `offer-ready-${props.offer.id}`;
</script>

<template>
    <article :id="`offer-${offer.id}`" class="wd-card ic-offer" :aria-labelledby="`offer-title-${offer.id}`">
        <header class="ic-offer-head">
            <div>
                <p class="ic-offer-from">
                    From {{ offer.brand_name }}
                    <template v-if="offer.campaign_id && offer.campaign_title">
                        ·
                        <span v-if="preview">{{ offer.campaign_title }}</span>
                        <Link v-else class="wd-link" :href="`/partner/campaigns/${offer.campaign_id}`">{{
                            offer.campaign_title
                        }}</Link>
                    </template>
                </p>
                <h3 :id="`offer-title-${offer.id}`" class="ic-card-title">
                    {{ offer.title }}
                    <StatusBadge v-if="offer.state === 'ended'" label="Ended" tone="neutral" />
                </h3>
            </div>
            <span
                v-if="preview && offer.state === 'shared'"
                class="wd-button wd-button-primary ic-claim"
                aria-hidden="true"
                ><AwardIcon :size="16" />Claim</span
            >
            <Button
                v-else-if="!preview && canClaim && offer.state === 'shared'"
                type="button"
                class="wd-button wd-button-primary ic-claim"
                :disabled="!readiness.ok"
                :ariaDescribedby="readyId"
                @click="$emit('claim', $event)"
                ><component :is="readiness.ok ? AwardIcon : Lock" :size="16" aria-hidden="true" />Claim<span
                    class="wd-sr-only"
                    >{{ ` ${offer.title}` }}</span
                ></Button
            >
        </header>
        <RewardBlock :terms="offer" />
        <RuleList :terms="offer" :stage-label="stageLabel" />
        <CapMeter :used="offer.granted" :cap="offer.cap_per_partner" label="Your awards approved" />
        <p v-if="offer.terms.trim()" class="ic-small ic-terms">{{ offer.terms }}</p>
        <p v-else-if="termsPlaceholder" class="ic-small ic-terms is-empty">{{ termsPlaceholder }}</p>
        <footer class="ic-ready" :class="{ 'is-ok': readiness.ok }">
            <p :id="readyId" class="ic-ready-text">
                <strong>{{ readiness.title }}</strong
                ><span class="ic-muted">{{ readiness.detail }}</span>
            </p>
            <div
                v-if="readiness.progress"
                class="ic-ready-bar"
                v-bind="
                    preview
                        ? { 'aria-hidden': 'true' }
                        : {
                              role: 'progressbar',
                              'aria-label': `Qualifying leads ready for ${offer.title}`,
                              'aria-valuemin': 0,
                              'aria-valuemax': readiness.progress.max,
                              'aria-valuenow': Math.min(readiness.progress.value, readiness.progress.max),
                          }
                "
            >
                <span
                    :style="{
                        width: `${Math.min(1, readiness.progress.value / readiness.progress.max) * 100}%`,
                    }"
                ></span>
            </div>
        </footer>
    </article>
</template>
