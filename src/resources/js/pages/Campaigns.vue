<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CampaignDraft from '../features/campaigns/CampaignDraft.vue';
import CampaignList from '../features/campaigns/CampaignList.vue';
import CampaignRecord from '../features/campaigns/CampaignRecord.vue';
import type {
    CampaignCounts,
    CampaignDetail,
    CampaignFilter,
    CampaignLead,
    CampaignListing,
    CampaignPartner,
} from '../features/campaigns/types';
import type { AwardState, Offer, PartnerOffer } from '../features/incentives/types';
import type { StageOption } from '../features/portals/types';
import BrandChrome from '../features/portals/BrandChrome.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';

defineProps<{
    experience: 'brand' | 'partner';
    workspace: { name: string };
    account: { name: string; email: string };
    canWrite: boolean;
    canManage: boolean;
    leads: (CampaignLead & { award?: AwardState | null })[];
    incentive: Offer | PartnerOffer | null;
    stages: StageOption[];
    selected: CampaignDetail | null;
    listing: CampaignListing | null;
    partners: CampaignPartner[];
    counts: CampaignCounts | null;
    filter: CampaignFilter;
}>();
</script>

<template>
    <Head :title="selected?.title ?? 'Campaigns'" />
    <component
        :is="experience === 'brand' ? BrandChrome : PartnerChrome"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="cp wd-portal-page flex flex-col gap-4 pt-5 pb-12">
            <template v-if="selected">
                <CampaignDraft
                    v-if="experience === 'brand' && canWrite && selected.status === 'draft'"
                    :key="selected.id"
                    :campaign="selected"
                    :partners="partners"
                />
                <CampaignRecord
                    v-else
                    :campaign="selected"
                    :brand="experience === 'brand'"
                    :can-manage="canManage"
                    :leads="leads"
                    :incentive="incentive"
                    :stages="stages"
                />
            </template>
            <CampaignList
                v-else-if="listing"
                :brand="experience === 'brand'"
                :can-write="canWrite"
                :listing="listing"
                :counts="counts"
                :filter="filter"
            />
        </main>
    </component>
</template>
