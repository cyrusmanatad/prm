<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import Breadcrumbs from '../../components/Breadcrumbs.vue';
import ContentSection from '../../components/ContentSection.vue';
import EmptyState from '../../components/EmptyState.vue';
import RecordHeader from '../../components/RecordHeader.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { dateOnly, dateTime, query } from '../admin/format';
import { calendarDate } from '../campaigns/format';
import AwardsTable from './AwardsTable.vue';
import CapMeter from './CapMeter.vue';
import { incentiveStates } from './format';
import RewardBlock from './RewardBlock.vue';
import RuleList from './RuleList.vue';
import type { Award, BrandIncentive } from './types';

const props = defineProps<{ incentive: BrandIncentive; canManage: boolean; stageLabel: (stage: string) => string }>();
const path = `/brand/incentives/${props.incentive.id}`;
const awardHref = (award: Award) => path + query({ award: award.id });

const ending = useForm({ version: props.incentive.version });
function end(event: Event): void {
    if (ending.processing) return;
    ending.version = props.incentive.version;
    confirmAction(
        'End incentive',
        `End “${props.incentive.title}” now? Partners can no longer claim new awards. Claims already sent stay open for your decision, and approved awards are kept.`,
        () => ending.post(`${path}/end`, { preserveScroll: true }),
        event,
        { tone: 'danger' },
    );
}
</script>

<template>
    <Breadcrumbs :items="[{ label: 'Incentives', href: '/brand/incentives' }, { label: incentive.title }]" />
    <RecordHeader eyebrow="Incentive" :title="incentive.title" heading-id="incentive-title-heading">
        <template #meta>
            <div>
                <dt class="wd-sr-only">Status</dt>
                <dd>
                    <StatusBadge
                        :label="incentiveStates[incentive.state].label"
                        :tone="incentiveStates[incentive.state].tone"
                    />
                </dd>
            </div>
            <div>
                <dt>Runs</dt>
                <dd>{{ calendarDate(incentive.starts_on) }} – {{ calendarDate(incentive.ends_on) }}</dd>
            </div>
            <div v-if="incentive.campaign_id">
                <dt>Campaign</dt>
                <dd>
                    <Link class="wd-link" :href="`/brand/campaigns/${incentive.campaign_id}`">{{
                        incentive.campaign_title
                    }}</Link>
                </dd>
            </div>
            <div v-if="incentive.shared_at">
                <dt>Shared</dt>
                <dd>
                    <time :datetime="incentive.shared_at" :title="dateTime(incentive.shared_at)">{{
                        dateOnly(incentive.shared_at)
                    }}</time>
                </dd>
            </div>
        </template>
        <template v-if="canManage && incentive.state === 'shared'" #actions>
            <Button type="button" class="wd-button wd-button-secondary" :disabled="ending.processing" @click="end"
                >End incentive…</Button
            >
        </template>
        <p v-if="ending.errors.version" class="wd-field-error" role="alert">{{ ending.errors.version }}</p>
    </RecordHeader>

    <div class="wd-record-columns">
        <div class="wd-record-main">
            <ContentSection title="Awards" heading-id="awards-heading">
                <template #header>
                    <header class="cp-section-head">
                        <h2 id="awards-heading">
                            Awards<span class="cp-count">{{ incentive.awards.length }}</span>
                        </h2>
                        <p class="cp-owner">Open a Partner to review, approve or mark fulfilled.</p>
                    </header>
                </template>
                <AwardsTable
                    v-if="incentive.awards.length"
                    :awards="incentive.awards"
                    caption="Awards for this incentive"
                    :href="awardHref"
                />
                <EmptyState
                    v-else
                    title="No claims yet"
                    description="Partners in the audience claim awards here when their registered leads qualify."
                />
            </ContentSection>
            <ContentSection title="Terms" heading-id="terms-heading">
                <template #header>
                    <header class="cp-section-head">
                        <h2 id="terms-heading">Terms</h2>
                        <p class="cp-owner"><Lock :size="14" aria-hidden="true" />Read-only since shared</p>
                    </header>
                </template>
                <p class="cp-brief">{{ incentive.terms || 'No additional terms.' }}</p>
            </ContentSection>
        </div>
        <aside class="wd-record-aside" aria-label="Offer summary">
            <ContentSection title="Offer" heading-id="offer-heading">
                <RewardBlock :terms="incentive" />
                <RuleList :terms="incentive" :stage-label="stageLabel" />
                <CapMeter :used="incentive.granted" :cap="incentive.cap_total" label="All awards approved" />
            </ContentSection>
            <ContentSection title="Audience" heading-id="audience-heading">
                <ul class="ic-checklist">
                    <li v-for="partner in incentive.audience" :key="partner.id" class="ic-check is-static">
                        <span class="ic-check-body">
                            <span class="ic-check-top">
                                <span class="ic-check-name">{{ partner.name ?? 'Ended relationship' }}</span>
                                <StatusBadge v-if="!partner.name" label="Relationship ended" tone="warning" />
                            </span>
                            <CapMeter
                                :used="partner.granted ?? 0"
                                :cap="incentive.cap_per_partner"
                                label="Awards approved"
                            />
                        </span>
                    </li>
                </ul>
            </ContentSection>
        </aside>
    </div>
</template>
