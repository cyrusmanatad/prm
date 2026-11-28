<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import ContentSection from '../../components/ContentSection.vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import FigureList, { type Figure } from '../../components/FigureList.vue';
import FilterChips from '../../components/FilterChips.vue';
import FormField from '../../components/FormField.vue';
import Pagination from '../../components/Pagination.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { dateOnly, query } from '../admin/format';
import FormDialog from '../admin/FormDialog.vue';
import { calendarDate, campaignStatuses, dueHint } from './format';
import type { CampaignCounts, CampaignFilter, CampaignListing, CampaignRow } from './types';

const props = defineProps<{
    brand: boolean;
    canWrite: boolean;
    listing: CampaignListing;
    counts: CampaignCounts | null;
    filter: CampaignFilter;
}>();

const path = computed(() => (props.brand ? '/brand/campaigns' : '/partner/campaigns'));
const href = (page: number, filter = props.filter) =>
    path.value + query({ status: filter === 'all' ? null : filter, page });

const columns = computed<DataColumn[]>(() =>
    props.brand
        ? [
              { key: 'title', label: 'Campaign' },
              { key: 'partner', label: 'Partner' },
              { key: 'status', label: 'Status' },
              { key: 'due', label: 'Due' },
              { key: 'leads', label: 'Leads' },
              { key: 'incentive', label: 'Incentive' },
          ]
        : [
              { key: 'title', label: 'Brief' },
              { key: 'due', label: 'Due' },
              { key: 'leads', label: 'Your leads' },
              { key: 'incentive', label: 'Incentive' },
          ],
);
// Status chips appear only when they hold campaigns, apart from All and the current choice.
const chips = computed(() =>
    props.counts
        ? (['all', 'draft', 'shared', 'ended'] as const)
              .map((value) => ({
                  value,
                  label: value === 'all' ? 'All' : value === 'draft' ? 'Drafts' : campaignStatuses[value].label,
                  count: props.counts![value],
              }))
              .filter((chip) => chip.value === 'all' || chip.count || chip.value === props.filter)
        : [],
);
// Brand figures over every campaign, not the current filter.
const figures = computed<Figure[]>(() => {
    const counts = props.counts;
    if (!counts) return [];
    const review = counts.awards ?? 0;
    return [
        { label: 'Shared briefs', value: counts.shared, scope: 'With an active Partner' },
        { label: 'Private drafts', value: counts.draft, scope: 'Only your workspace sees them' },
        { label: 'Leads from campaigns', value: counts.leads ?? 0, scope: 'Registered with a source campaign' },
        {
            label: 'Awards to review',
            value: review,
            attention: review > 0,
            ...(review
                ? { href: '/brand/incentives?tab=awards', linkLabel: 'Review in Incentives' }
                : { scope: 'Nothing waiting' }),
        },
    ];
});
const empty = computed(() => (props.counts ? props.counts.all === 0 : props.listing.total === 0));

const dialog = ref<InstanceType<typeof FormDialog>>();
const create = useForm({ title: '' });
function submit(): void {
    if (create.processing) return;
    // A successful create opens the new draft.
    create.post(path.value, { onSuccess: () => create.reset() });
}
</script>

<template>
    <header class="cp-head">
        <div>
            <h1>Campaigns</h1>
            <p class="wd-description">
                <template v-if="brand">
                    Briefs you share with one Partner at a time. Drafts stay private to your workspace until you share
                    them.
                </template>
                <template v-else>Briefs that Brands share with your workspace. Shared briefs are read-only.</template>
            </p>
        </div>
        <Button
            v-if="canWrite"
            type="button"
            class="wd-button wd-button-primary"
            @click="dialog?.open($event.currentTarget as HTMLElement)"
            ><Plus :size="16" aria-hidden="true" />New campaign</Button
        >
    </header>

    <FigureList v-if="brand && !empty" label="Campaign summary" :figures="figures" />

    <ContentSection v-if="empty" :title="brand ? 'All campaigns' : 'Shared briefs'" heading-id="list-heading">
        <EmptyState
            :title="brand ? 'No campaigns yet' : 'No shared briefs yet'"
            :description="
                brand
                    ? 'Write a brief as a private draft, then share it with one active Partner. Their members can read it; other Partners cannot.'
                    : 'Campaign briefs appear here when a connected Brand shares one with your workspace.'
            "
        >
            <Button
                v-if="canWrite"
                type="button"
                class="wd-button wd-button-primary cp-empty-action"
                @click="dialog?.open($event.currentTarget as HTMLElement)"
                ><Plus :size="16" aria-hidden="true" />Create your first campaign</Button
            >
        </EmptyState>
    </ContentSection>

    <ContentSection v-else :title="brand ? 'All campaigns' : 'Shared briefs'" heading-id="list-heading">
        <template #header>
            <header>
                <h2 id="list-heading">
                    {{ brand ? 'All campaigns' : 'Shared briefs'
                    }}<span class="cp-count">{{ (counts?.all ?? listing.total).toLocaleString('en') }}</span>
                </h2>
            </header>
        </template>
        <FilterChips
            v-if="brand && counts"
            label="Filter by status"
            compact
            :chips="chips"
            :current="filter"
            :href-for="(value) => href(1, value as CampaignFilter)"
        />
        <DataTable
            v-if="listing.data.length"
            :caption="brand ? 'Campaigns' : 'Shared briefs'"
            :columns="columns"
            :rows="listing.data"
            :row-key="(row: CampaignRow) => row.id"
        >
            <template #cell-title="{ row }">
                <span class="cp-title">
                    <Link :href="`${path}/${row.id}`" class="wd-link">{{ row.title }}</Link>
                    <span class="cp-sub">{{
                        brand ? `Created ${dateOnly(row.created_at)}` : `From ${row.brand_name}`
                    }}</span>
                </span>
            </template>
            <template #cell-partner="{ row }">
                <span v-if="row.creator_name">{{ row.creator_name }}</span>
                <span v-else class="wd-hint">{{ row.partnership_id ? 'Relationship ended' : 'Not shared' }}</span>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :label="campaignStatuses[row.status].label" :tone="campaignStatuses[row.status].tone" />
            </template>
            <template #cell-due="{ row }">
                <time v-if="row.due_date" :datetime="row.due_date">{{ calendarDate(row.due_date) }}</time>
                <span v-else class="wd-hint">Not set</span>
                <span v-if="row.due_date && row.status === 'shared'" class="cp-sub">{{ dueHint(row.due_date) }}</span>
            </template>
            <template #cell-leads="{ row }">
                <span v-if="row.status === 'draft'" class="wd-hint">—</span>
                <template v-else>{{ row.leads.toLocaleString('en') }}</template>
            </template>
            <template #cell-incentive="{ row }">
                <Link
                    v-if="row.incentive"
                    class="wd-link"
                    :href="`${brand ? '/brand/incentives/' + row.incentive.id : '/partner/incentives'}`"
                    >{{ row.incentive.title }}</Link
                >
                <span v-else class="wd-hint">None</span>
            </template>
        </DataTable>
        <EmptyState v-else title="No campaigns with this status" description="Choose another status to see them." />
        <Pagination
            v-if="listing.total > listing.per_page || listing.current_page > 1"
            label="Campaign pages"
            noun="campaigns"
            :page="listing.current_page"
            :per-page="listing.per_page"
            :total="listing.total"
            :count="listing.data.length"
            :href-for="(page) => href(page)"
        />
    </ContentSection>

    <FormDialog
        v-if="canWrite"
        id="new-campaign"
        ref="dialog"
        title="New campaign"
        description="It starts as a private draft. Only your workspace can see it."
        submit-label="Create draft"
        :processing="create.processing"
        :errors="create.errors"
        @submit="submit"
    >
        <FormField
            id="campaign-title"
            v-model="create.title"
            label="Campaign name"
            :maxlength="160"
            :error="create.errors.title"
        />
    </FormDialog>
</template>
