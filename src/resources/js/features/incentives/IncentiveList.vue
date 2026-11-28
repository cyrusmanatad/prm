<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Clock, Plus } from '@lucide/vue';
import ContentSection from '../../components/ContentSection.vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import FigureList, { type Figure } from '../../components/FigureList.vue';
import FilterChips from '../../components/FilterChips.vue';
import FormField from '../../components/FormField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import TabNav from '../../components/TabNav.vue';
import { Button } from '../../components/ui/button';
import { query } from '../admin/format';
import FormDialog from '../admin/FormDialog.vue';
import { calendarDate } from '../campaigns/format';
import AwardsTable from './AwardsTable.vue';
import { awardStates, incentiveStates, rewardKinds, rewardValue, totals } from './format';
import type { Award, MoneyTotal, Offer } from './types';

type OfferStatus = 'all' | 'draft' | 'shared' | 'ended';
type AwardFilter = 'pending' | 'approved' | 'fulfilled' | 'closed' | 'all';
const props = defineProps<{
    canManage: boolean;
    tab: 'offers' | 'awards';
    status: OfferStatus;
    awardState: AwardFilter;
    figures: { offers: number; review: number; owed: MoneyTotal[]; delivered: MoneyTotal[]; owedCount: number };
    offers: { rows: Offer[]; counts: Record<OfferStatus, number> };
    awardList: { rows: Award[]; counts: Record<AwardFilter, number> };
}>();

const path = '/brand/incentives';
const href = (change: Partial<{ tab: string; status: string; awards: string; award: string }> = {}) => {
    const next = { tab: props.tab, status: props.status, awards: props.awardState, award: '', ...change };
    return (
        path +
        query({
            tab: next.tab === 'offers' ? null : next.tab,
            status: next.tab === 'offers' && next.status !== 'all' ? next.status : null,
            awards: next.tab === 'awards' && next.awards !== 'pending' ? next.awards : null,
            award: next.award || null,
        })
    );
};
const awardHref = (award: Award) => href({ award: award.id });

const figureList = computed<Figure[]>(() => [
    { label: 'Active offers', value: props.figures.offers, scope: 'Shared with Partners' },
    {
        label: 'Awards to review',
        value: props.figures.review,
        attention: props.figures.review > 0,
        ...(props.figures.review
            ? { href: href({ tab: 'awards', awards: 'pending' }), linkLabel: 'Review now' }
            : { scope: 'Nothing waiting' }),
    },
    {
        label: 'Approved, not yet delivered',
        value: totals(props.figures.owed),
        scope: `${props.figures.owedCount} ${props.figures.owedCount === 1 ? 'award' : 'awards'} · cash only`,
    },
    { label: 'Delivered this year', value: totals(props.figures.delivered), scope: 'Cash awards marked fulfilled' },
]);
const tabs = computed(() => [
    { href: href({ tab: 'offers' }), label: `Offers (${props.offers.counts.all})`, current: props.tab === 'offers' },
    {
        href: href({ tab: 'awards' }),
        label: `Awards${props.figures.review ? ` (${props.figures.review} to review)` : ''}`,
        current: props.tab === 'awards',
    },
]);
const offerChips = computed(() =>
    (['all', 'draft', 'shared', 'ended'] as const)
        .map((value) => ({
            value,
            label: value === 'all' ? 'All' : value === 'draft' ? 'Drafts' : incentiveStates[value].label,
            count: props.offers.counts[value],
        }))
        .filter((chip) => chip.value === 'all' || chip.count || chip.value === props.status),
);
const awardChips = computed(() =>
    (['pending', 'approved', 'fulfilled', 'closed', 'all'] as const)
        .map((value) => ({
            value,
            label:
                value === 'pending'
                    ? 'To review'
                    : value === 'closed'
                      ? 'Rejected or cancelled'
                      : value === 'all'
                        ? 'All'
                        : awardStates[value].label,
            count: props.awardList.counts[value] ?? 0,
        }))
        .filter((chip) => ['pending', 'all'].includes(chip.value) || chip.count || chip.value === props.awardState),
);
const columns: DataColumn[] = [
    { key: 'title', label: 'Incentive' },
    { key: 'reward', label: 'Reward' },
    { key: 'audience', label: 'Audience' },
    { key: 'state', label: 'Status' },
    { key: 'awards', label: 'Awards' },
    { key: 'runs', label: 'Runs' },
];
const awardEmpty: Record<AwardFilter, [string, string]> = {
    pending: ['Nothing to review', 'New claims from Partners appear here. You approve or reject each one.'],
    approved: ['No approved awards waiting', 'Approved awards stay here until you mark them fulfilled.'],
    fulfilled: ['No fulfilled awards yet', 'Awards you mark fulfilled appear here.'],
    closed: ['No rejected or cancelled awards', 'Rejected and automatically cancelled claims appear here.'],
    all: ['No awards yet', 'Partners claim awards when their registered leads qualify.'],
};

const dialog = ref<InstanceType<typeof FormDialog>>();
const create = useForm({ title: '' });
function submit(): void {
    if (!create.processing) create.post(path, { onSuccess: () => create.reset() });
}
</script>

<template>
    <header class="cp-head">
        <div>
            <h1>Incentives</h1>
            <p class="wd-description">
                Offer rewards to Partners and approve the awards they earn with registered leads. WeavDash records what
                is owed; you pay or deliver it outside WeavDash.
            </p>
        </div>
        <Button
            v-if="canManage"
            type="button"
            class="wd-button wd-button-primary"
            @click="dialog?.open($event.currentTarget as HTMLElement)"
            ><Plus :size="16" aria-hidden="true" />New incentive</Button
        >
    </header>

    <ContentSection v-if="!offers.counts.all" title="Offers" heading-id="offers-heading">
        <EmptyState
            title="No incentives yet"
            description="Create an offer, for example a cash bonus per onboarded lead, and share it with selected Partners. They claim awards; you approve each one."
        >
            <Button
                v-if="canManage"
                type="button"
                class="wd-button wd-button-primary cp-empty-action"
                @click="dialog?.open($event.currentTarget as HTMLElement)"
                ><Plus :size="16" aria-hidden="true" />Create your first incentive</Button
            >
        </EmptyState>
    </ContentSection>
    <template v-else>
        <FigureList label="Incentive summary" :figures="figureList" />
        <TabNav label="Incentive views" :tabs="tabs" />

        <ContentSection v-if="tab === 'offers'" title="Offers" heading-id="offers-heading">
            <FilterChips
                label="Filter offers by status"
                compact
                :chips="offerChips"
                :current="status"
                :href-for="(value) => href({ status: value })"
            />
            <DataTable
                v-if="offers.rows.length"
                caption="Incentive offers"
                :columns="columns"
                :rows="offers.rows"
                :row-key="(row: Offer) => row.id"
            >
                <template #cell-title="{ row }">
                    <span class="cp-title">
                        <Link class="wd-link" :href="`${path}/${row.id}`">{{ row.title }}</Link>
                        <span class="cp-sub">{{
                            row.campaign_title ? `Campaign: ${row.campaign_title}` : 'Any registered lead'
                        }}</span>
                    </span>
                </template>
                <template #cell-reward="{ row }">
                    {{ rewardValue(row) }}<span class="cp-sub">{{ rewardKinds[row.reward_type] }}</span>
                </template>
                <template #cell-audience="{ row }">
                    <template v-if="row.audience.length">{{
                        row.audience
                            .map((partner: Offer['audience'][number]) => partner.name ?? 'Ended relationship')
                            .join(', ')
                    }}</template>
                    <span v-else class="wd-hint">Not chosen</span>
                </template>
                <template #cell-state="{ row }">
                    <StatusBadge :label="incentiveStates[row.state].label" :tone="incentiveStates[row.state].tone" />
                </template>
                <template #cell-awards="{ row }">
                    <span v-if="row.state === 'draft'" class="wd-hint">—</span>
                    <template v-else>{{ row.granted }}{{ row.cap_total ? ` of ${row.cap_total}` : '' }}</template>
                    <span v-if="row.pending" class="ic-flag"
                        ><Clock :size="12" aria-hidden="true" />{{ row.pending }} pending</span
                    >
                </template>
                <template #cell-runs="{ row }">
                    <template v-if="row.starts_on && row.ends_on"
                        >{{ calendarDate(row.starts_on) }} – {{ calendarDate(row.ends_on) }}</template
                    >
                    <span v-else class="wd-hint">Not set</span>
                </template>
            </DataTable>
            <EmptyState v-else title="No offers with this status" description="Choose another status to see them." />
        </ContentSection>

        <ContentSection v-else title="Awards" heading-id="awards-heading">
            <template #header>
                <header class="cp-section-head">
                    <h2 id="awards-heading">Awards</h2>
                    <p class="cp-owner">Open a Partner to review, approve or mark fulfilled.</p>
                </header>
            </template>
            <FilterChips
                label="Filter awards by status"
                compact
                :chips="awardChips"
                :current="awardState"
                :href-for="(value) => href({ awards: value })"
            />
            <AwardsTable
                v-if="awardList.rows.length"
                :awards="awardList.rows"
                caption="Awards"
                :href="awardHref"
                show-incentive
            />
            <EmptyState v-else :title="awardEmpty[awardState][0]" :description="awardEmpty[awardState][1]" />
        </ContentSection>
    </template>

    <FormDialog
        v-if="canManage"
        id="new-incentive"
        ref="dialog"
        title="New incentive"
        description="It starts as a private draft. Add the reward, rule and Partners next, then share it."
        submit-label="Create draft"
        :processing="create.processing"
        :errors="create.errors"
        @submit="submit"
    >
        <FormField
            id="incentive-name"
            v-model="create.title"
            label="Incentive name"
            :maxlength="160"
            :error="create.errors.title"
        />
    </FormDialog>
</template>
