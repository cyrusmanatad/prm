<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AwardPanel from '../features/incentives/AwardPanel.vue';
import IncentiveForm from '../features/incentives/IncentiveForm.vue';
import IncentiveList from '../features/incentives/IncentiveList.vue';
import IncentiveRecord from '../features/incentives/IncentiveRecord.vue';
import PartnerIncentives from '../features/incentives/PartnerIncentives.vue';
import type {
    Award,
    AwardDetail,
    BrandIncentive,
    MoneyTotal,
    Offer,
    PartnerAward,
    PartnerOffer,
} from '../features/incentives/types';
import BrandChrome from '../features/portals/BrandChrome.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import { stageLabel } from '../features/portals/activity';
import type { StageOption } from '../features/portals/types';

type OfferStatus = 'all' | 'draft' | 'shared' | 'ended';
type AwardFilter = 'pending' | 'approved' | 'fulfilled' | 'closed' | 'all';
const props = defineProps<{
    experience: 'brand' | 'partner';
    workspace: { name: string };
    account: { name: string; email: string };
    canWrite: boolean;
    stages: StageOption[];
    // Brand
    selected?: BrandIncentive | null;
    award?: AwardDetail | null;
    partners?: { id: string; name: string }[];
    campaigns?: { id: string; title: string; partnership_id: string; partner_name: string }[];
    tab?: 'offers' | 'awards';
    offerStatus?: OfferStatus;
    awardState?: AwardFilter;
    figures?: Record<string, unknown>;
    offers?: { rows: Offer[]; counts: Record<OfferStatus, number> } | PartnerOffer[];
    awardList?: { rows: Award[]; counts: Record<AwardFilter, number> };
    // Partner
    awards?: PartnerAward[];
}>();
const label = (stage: string) => stageLabel(props.stages, stage);

// The review panel is part of the address, so closing it drops only the award parameter.
function closeAward(): void {
    const url = new URL(window.location.href);
    url.searchParams.delete('award');
    router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head :title="selected?.title ?? 'Incentives'" />
    <component
        :is="experience === 'brand' ? BrandChrome : PartnerChrome"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="cp ic wd-portal-page flex flex-col gap-4 pt-5 pb-12">
            <PartnerIncentives
                v-if="experience === 'partner'"
                :can-write="canWrite"
                :offers="offers as PartnerOffer[]"
                :awards="awards ?? []"
                :figures="figures as { offers: number; review: number; approved: number; received: number }"
                :stage-label="label"
            />
            <template v-else-if="selected">
                <IncentiveForm
                    v-if="selected.state === 'draft' && canWrite"
                    :key="selected.id"
                    :incentive="selected"
                    :partners="partners ?? []"
                    :campaigns="campaigns ?? []"
                    :workspace-name="workspace.name"
                    :stage-label="label"
                />
                <IncentiveRecord v-else :incentive="selected" :can-manage="canWrite" :stage-label="label" />
            </template>
            <IncentiveList
                v-else
                :can-manage="canWrite"
                :tab="tab ?? 'offers'"
                :status="offerStatus ?? 'all'"
                :award-state="awardState ?? 'pending'"
                :figures="
                    figures as {
                        offers: number;
                        review: number;
                        owed: MoneyTotal[];
                        delivered: MoneyTotal[];
                        owedCount: number;
                    }
                "
                :offers="offers as { rows: Offer[]; counts: Record<OfferStatus, number> }"
                :award-list="awardList!"
            />
        </main>
        <AwardPanel
            v-if="award"
            :key="award.id"
            :award="award"
            :can-manage="canWrite"
            :stage-label="label"
            @close="closeAward"
        />
    </component>
</template>
