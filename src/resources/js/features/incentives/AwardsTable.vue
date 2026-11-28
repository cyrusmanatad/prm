<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateOnly, dateTime } from '../admin/format';
import { awardStates, rewardValue } from './format';
import type { Award } from './types';

/** Brand awards. Each Partner name opens the review panel through `href`, keeping the list in place. */
const props = defineProps<{
    awards: Award[];
    caption: string;
    href: (award: Award) => string;
    showIncentive?: boolean;
}>();
const columns = computed<DataColumn[]>(() => [
    { key: 'partner', label: 'Partner' },
    { key: 'leads', label: 'Leads' },
    { key: 'reward', label: 'Reward' },
    { key: 'state', label: 'Status' },
    { key: 'updated', label: 'Claimed' },
]);
const reward = (award: Award) =>
    rewardValue({ ...award, amount_minor: award.amount_minor, reward_text: award.reward_text });
</script>

<template>
    <DataTable :caption="caption" :columns="columns" :rows="awards" :row-key="(row: Award) => row.id">
        <template #cell-partner="{ row }">
            <span class="cp-title">
                <Link class="wd-link" :href="href(row)" preserve-scroll preserve-state>{{
                    row.partner_name ?? 'Ended relationship'
                }}</Link>
                <span v-if="showIncentive" class="cp-sub">{{ row.incentive_title }}</span>
            </span>
        </template>
        <template #cell-leads="{ row }">{{ row.lead_count }} {{ row.lead_count === 1 ? 'lead' : 'leads' }}</template>
        <template #cell-reward="{ row }">{{ reward(row) }}</template>
        <template #cell-state="{ row }">
            <StatusBadge :label="awardStates[row.state].label" :tone="awardStates[row.state].tone" />
        </template>
        <template #cell-updated="{ row }">
            <time :datetime="row.claimed_at" :title="dateTime(row.claimed_at)">{{ dateOnly(row.claimed_at) }}</time>
        </template>
    </DataTable>
</template>
