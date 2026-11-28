<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Eye, Send, TriangleAlert } from '@lucide/vue';
import Breadcrumbs from '../../components/Breadcrumbs.vue';
import ContentSection from '../../components/ContentSection.vue';
import FieldGroup from '../../components/FieldGroup.vue';
import FormField from '../../components/FormField.vue';
import RecordHeader from '../../components/RecordHeader.vue';
import SelectField from '../../components/SelectField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { calendarDate } from '../campaigns/format';
import { today } from '../finance/amounts';
import { fromMinor, toMinor } from '../pipeline/money';
import { incentiveStates, rewardKinds } from './format';
import RewardBlock from './RewardBlock.vue';
import RuleList from './RuleList.vue';
import type { BrandIncentive, RewardType } from './types';

type CampaignChoice = { id: string; title: string; partnership_id: string; partner_name: string };
const props = defineProps<{
    incentive: BrandIncentive;
    partners: { id: string; name: string }[];
    campaigns: CampaignChoice[];
    workspaceName: string;
    stageLabel: (stage: string) => string;
}>();

const path = computed(() => `/brand/incentives/${props.incentive.id}`);
const form = useForm({
    version: 1,
    title: '',
    reward_type: 'cash' as RewardType,
    amount: '',
    currency: 'PHP',
    reward_text: '',
    terms: '',
    rule_stage: 'onboarded',
    leads_per_award: '1',
    campaign_id: '',
    audience: [] as string[],
    starts_on: '',
    ends_on: '',
    cap_per_partner: '',
    cap_total: '',
});
function load(): void {
    const i = props.incentive;
    form.defaults({
        version: i.version,
        title: i.title,
        reward_type: i.reward_type,
        amount: i.amount_minor && i.currency ? fromMinor(i.amount_minor, i.currency) : '',
        currency: i.currency ?? 'PHP',
        reward_text: i.reward_text,
        terms: i.terms,
        rule_stage: i.rule_stage,
        leads_per_award: String(i.leads_per_award),
        campaign_id: i.campaign_id ?? '',
        audience: i.audience.map((partner) => partner.id),
        starts_on: i.starts_on ?? today(),
        ends_on: i.ends_on ?? '',
        cap_per_partner: i.cap_per_partner ? String(i.cap_per_partner) : '',
        cap_total: i.cap_total ? String(i.cap_total) : '',
    });
    form.reset();
    form.clearErrors();
}
watch(() => [props.incentive.id, props.incentive.version], load, { immediate: true });

// A campaign is shared with one Partner, so choosing it fixes the audience (D44).
const campaign = computed(() => props.campaigns.find((item) => item.id === form.campaign_id) ?? null);
watch(campaign, (value) => {
    if (value) form.audience = [value.partnership_id];
});
const campaignOptions = computed(() => [
    { value: '', label: 'Any registered lead' },
    ...props.campaigns.map((item) => ({ value: item.id, label: `${item.title} — ${item.partner_name}` })),
]);
const stageOptions = computed(() =>
    ['pitched', 'negotiating', 'onboarded', 'active', 'repeat-client'].map((value) => ({
        value,
        label: `${props.stageLabel(value)} or later`,
    })),
);

const amountMinor = computed(() => (form.amount.trim() ? toMinor(form.amount, form.currency || 'PHP') : null));
const preview = computed(() => ({
    title: form.title.trim() || 'Untitled incentive',
    reward_type: form.reward_type,
    amount_minor: amountMinor.value,
    currency: form.currency,
    reward_text: form.reward_text,
    rule_stage: form.rule_stage,
    leads_per_award: Number(form.leads_per_award) || 1,
    starts_on: form.starts_on || null,
    ends_on: form.ends_on || null,
    campaign_title: campaign.value?.title ?? null,
}));

const fieldErrors = computed(() => form.errors as Record<string, string | undefined>);
// Client checks mirror the server rules so the summary can name every field at once.
const summary = ref<HTMLElement>();
const labels: Record<string, [string, string]> = {
    title: ['incentive-title', 'Title'],
    amount_minor: ['incentive-amount', 'Amount'],
    currency: ['incentive-currency', 'Currency'],
    reward_text: ['incentive-reward', 'Reward'],
    terms: ['incentive-terms', 'Terms'],
    leads_per_award: ['incentive-leads', 'Leads per award'],
    campaign_id: ['incentive-campaign-trigger', 'Source campaign'],
    audience: ['incentive-audience', 'Partners'],
    starts_on: ['incentive-start', 'Starts'],
    ends_on: ['incentive-end', 'Ends'],
    cap_per_partner: ['incentive-cap-partner', 'Awards per Partner'],
    cap_total: ['incentive-cap-total', 'Awards in total'],
};
const errorList = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key !== 'version')
        .map(([key, message]) => ({
            key,
            target: labels[key.split('.')[0]!]?.[0] ?? 'incentive-title',
            message,
        })),
);
function check(sharing: boolean): boolean {
    form.clearErrors();
    const errors: Record<string, string> = {};
    if (!form.title.trim()) errors.title = 'Enter a title.';
    if (form.amount.trim() && !amountMinor.value) errors.amount_minor = 'Enter an amount greater than zero.';
    if (sharing) {
        if (form.reward_type === 'cash' && !amountMinor.value) errors.amount_minor = 'Enter the reward amount.';
        if (form.reward_type !== 'cash' && !form.reward_text.trim()) errors.reward_text = 'Describe the reward.';
        if (!form.terms.trim()) errors.terms = 'Write the terms Partners will see.';
        if (!form.audience.length) errors.audience = 'Choose at least one Partner.';
        if (!form.starts_on) errors.starts_on = 'Choose a start date.';
        if (!form.ends_on) errors.ends_on = 'Choose an end date.';
        else if (form.ends_on < (form.starts_on || '') || form.ends_on < today())
            errors.ends_on = 'The end date must be on or after the start date and not in the past.';
    }
    const perPartner = Number(form.cap_per_partner);
    const total = Number(form.cap_total);
    if (form.cap_per_partner && form.cap_total && perPartner > total)
        errors.cap_per_partner = 'Awards per Partner cannot be more than awards in total.';
    if (Object.keys(errors).length) {
        form.setError(errors);
        void nextTick(() => summary.value?.focus());
        return false;
    }
    return true;
}
watch(
    () => form.errors,
    (errors) => {
        if (Object.keys(errors).length && !form.processing) void nextTick(() => summary.value?.focus());
    },
);

function send(share: boolean): void {
    form.transform((data) => ({
        version: data.version,
        title: data.title.trim(),
        terms: data.terms,
        reward_type: data.reward_type,
        amount_minor: data.reward_type === 'cash' ? amountMinor.value : null,
        currency: data.reward_type === 'cash' ? data.currency.trim().toUpperCase() : null,
        reward_text: data.reward_type === 'cash' ? null : data.reward_text,
        rule_stage: data.rule_stage,
        leads_per_award: Number(data.leads_per_award) || 1,
        campaign_id: data.campaign_id || null,
        audience: data.audience,
        starts_on: data.starts_on || null,
        ends_on: data.ends_on || null,
        cap_per_partner: data.cap_per_partner ? Number(data.cap_per_partner) : null,
        cap_total: data.cap_total ? Number(data.cap_total) : null,
    })).patch(`${path.value}/${share ? 'share' : 'draft'}`, { preserveScroll: true });
}
function saveDraft(): void {
    if (!form.processing && check(false)) send(false);
}
function share(event: Event): void {
    if (form.processing || !check(true)) return;
    const names = props.partners
        .filter((partner) => form.audience.includes(partner.id))
        .map((partner) => partner.name)
        .join(', ');
    confirmAction(
        'Share incentive',
        `Share “${form.title.trim()}” with ${names || 'the chosen Partners'} from ${calendarDate(form.starts_on)} to ${calendarDate(form.ends_on)}? They can read the reward and terms and claim awards. The terms become read-only. You approve every award.`,
        () => send(true),
        event,
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
                    <StatusBadge :label="incentiveStates.draft.label" :tone="incentiveStates.draft.tone" />
                </dd>
            </div>
            <div>
                <dt>Visible to</dt>
                <dd>Your workspace only</dd>
            </div>
        </template>
    </RecordHeader>

    <div class="wd-record-columns">
        <form class="wd-record-main" novalidate @submit.prevent="saveDraft">
            <div v-if="errorList.length" ref="summary" class="wd-warning ic-error-summary" role="alert" tabindex="-1">
                <TriangleAlert :size="16" aria-hidden="true" />
                <div>
                    <strong>Check {{ errorList.length === 1 ? '1 field' : `${errorList.length} fields` }}</strong>
                    <ul>
                        <li v-for="item in errorList" :key="item.key">
                            <a class="wd-link" :href="`#${item.target}`">{{ item.message }}</a>
                        </li>
                    </ul>
                </div>
            </div>
            <ContentSection title="Offer" heading-id="offer-heading">
                <FormField
                    id="incentive-title"
                    v-model="form.title"
                    label="Title"
                    :maxlength="160"
                    :error="fieldErrors.title"
                />
                <fieldset class="ic-segmented">
                    <legend class="ic-legend">Reward type</legend>
                    <label v-for="(label, value) in rewardKinds" :key="value">
                        <input v-model="form.reward_type" type="radio" name="reward_type" :value="value" />
                        <span
                            ><span class="ic-choice-title">{{ label }}</span
                            ><span class="ic-choice-sub">{{
                                {
                                    cash: 'A fixed amount per award',
                                    non_cash: 'Product, service or perk',
                                    percent: 'Described in your terms',
                                }[value]
                            }}</span></span
                        >
                    </label>
                </fieldset>
                <div v-if="form.reward_type === 'cash'" class="ic-pair">
                    <FormField
                        id="incentive-amount"
                        v-model="form.amount"
                        label="Amount per award"
                        inputmode="decimal"
                        :required="false"
                        hint="Recorded as owed when you approve an award. WeavDash does not send money."
                        :error="fieldErrors.amount_minor"
                    />
                    <FormField
                        id="incentive-currency"
                        v-model="form.currency"
                        label="Currency"
                        :maxlength="3"
                        hint="Three-letter code, for example PHP."
                        :error="fieldErrors.currency"
                    />
                </div>
                <FormField
                    v-else
                    id="incentive-reward"
                    v-model="form.reward_text"
                    label="Reward"
                    :required="false"
                    :maxlength="200"
                    :hint="
                        form.reward_type === 'percent'
                            ? 'For example: 5% bonus on the first order.'
                            : 'For example: starter product bundle.'
                    "
                    :error="fieldErrors.reward_text"
                />
                <FieldGroup
                    id="incentive-terms"
                    label="Terms"
                    hint="Shown to Partners. Say how and when you deliver the reward."
                    :error="fieldErrors.terms"
                    v-slot="{ id, describedby, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="form.terms"
                        rows="5"
                        maxlength="4000"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid"
                    ></textarea>
                </FieldGroup>
            </ContentSection>

            <ContentSection title="Who qualifies" heading-id="qualifies-heading">
                <div class="ic-pair">
                    <SelectField
                        id="incentive-stage"
                        v-model="form.rule_stage"
                        label="Lead reaches"
                        :options="stageOptions"
                        :searchable="false"
                        :error="fieldErrors.rule_stage"
                    />
                    <FormField
                        id="incentive-leads"
                        v-model="form.leads_per_award"
                        label="Leads per award"
                        inputmode="numeric"
                        :maxlength="2"
                        :error="fieldErrors.leads_per_award"
                    />
                </div>
                <SelectField
                    id="incentive-campaign"
                    v-model="form.campaign_id"
                    label="Source campaign (optional)"
                    hint="Limit the offer to leads registered with this brief as their source campaign."
                    :options="campaignOptions"
                    :error="fieldErrors.campaign_id"
                />
                <fieldset
                    id="incentive-audience"
                    class="ic-audience"
                    tabindex="-1"
                    :aria-describedby="`incentive-audience-hint${fieldErrors.audience ? ' incentive-audience-error' : ''}`"
                >
                    <legend class="ic-legend">Partners</legend>
                    <p id="incentive-audience-hint" class="wd-hint">
                        {{
                            campaign
                                ? `Set by the campaign: it is shared with ${campaign.partner_name} only.`
                                : 'Active Partners only. Each sees the offer and only their own awards.'
                        }}
                    </p>
                    <ul v-if="partners.length" class="ic-checklist">
                        <li v-for="partner in partners" :key="partner.id">
                            <label class="ic-check">
                                <input
                                    v-model="form.audience"
                                    type="checkbox"
                                    :value="partner.id"
                                    :disabled="!!campaign"
                                />
                                <span class="ic-check-name">{{ partner.name }}</span>
                            </label>
                        </li>
                    </ul>
                    <p v-else class="wd-hint">
                        No active Partners yet.
                        <Link href="/brand" class="wd-link">Invite one from the Partner hub</Link>.
                    </p>
                    <p v-if="fieldErrors.audience" id="incentive-audience-error" class="wd-field-error">
                        {{ fieldErrors.audience }}
                    </p>
                </fieldset>
            </ContentSection>

            <ContentSection title="Dates and limits" heading-id="limits-heading">
                <div class="ic-pair">
                    <FormField
                        id="incentive-start"
                        v-model="form.starts_on"
                        type="date"
                        label="Starts"
                        :required="false"
                        :error="fieldErrors.starts_on"
                    />
                    <FormField
                        id="incentive-end"
                        v-model="form.ends_on"
                        type="date"
                        label="Ends"
                        :required="false"
                        :error="fieldErrors.ends_on"
                    />
                </div>
                <div class="ic-pair">
                    <FormField
                        id="incentive-cap-partner"
                        v-model="form.cap_per_partner"
                        label="Awards per Partner (optional)"
                        inputmode="numeric"
                        :required="false"
                        hint="Leave empty for no limit."
                        :error="fieldErrors.cap_per_partner"
                    />
                    <FormField
                        id="incentive-cap-total"
                        v-model="form.cap_total"
                        label="Awards in total (optional)"
                        inputmode="numeric"
                        :required="false"
                        hint="Partners never see this number."
                        :error="fieldErrors.cap_total"
                    />
                </div>
            </ContentSection>

            <p v-if="fieldErrors.version" role="alert" class="wd-field-error">
                {{ fieldErrors.version }} <Link class="wd-link" :href="path">Reopen incentive</Link>
            </p>
            <div class="cp-actions">
                <Link class="wd-button wd-button-secondary" href="/brand/incentives">Cancel</Link>
                <Button type="submit" class="wd-button wd-button-secondary" :disabled="form.processing"
                    >Save draft</Button
                >
                <Button type="button" class="wd-button wd-button-primary" :disabled="form.processing" @click="share"
                    ><Send :size="16" aria-hidden="true" />Share incentive…</Button
                >
            </div>
        </form>
        <aside class="wd-record-aside cp-sticky" aria-label="Partner preview">
            <ContentSection title="What Partners see" heading-id="preview-heading">
                <template #header>
                    <p id="preview-heading" class="ic-preview-label">
                        <Eye :size="14" aria-hidden="true" />What Partners see
                    </p>
                </template>
                <div class="ic-preview" aria-live="polite">
                    <p class="ic-offer-from">From {{ workspaceName }}</p>
                    <p class="ic-card-title">{{ preview.title }}</p>
                    <RewardBlock :terms="preview" />
                    <RuleList :terms="preview" :stage-label="stageLabel" />
                    <p v-if="form.terms.trim()" class="ic-small ic-terms">{{ form.terms }}</p>
                </div>
            </ContentSection>
        </aside>
    </div>
</template>
