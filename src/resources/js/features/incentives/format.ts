import type { BadgeTone } from '../../components/StatusBadge.vue';
import { formatMoney } from '../finance/amounts';
import { calendarDate } from '../campaigns/format';
import type { AwardState, IncentiveState, IncentiveTerms, MoneyTotal } from './types';

export const rewardKinds = { cash: 'Cash', non_cash: 'Non-cash', percent: 'Percentage bonus' } as const;

/** The reward as one line: an amount for cash, otherwise the Brand's description. */
export function rewardValue(
    terms: Pick<IncentiveTerms, 'reward_type' | 'amount_minor' | 'currency' | 'reward_text'>,
): string {
    if (terms.reward_type === 'cash')
        return terms.amount_minor && terms.currency
            ? formatMoney(terms.amount_minor, terms.currency)
            : 'Amount not set';
    return terms.reward_text || 'Reward not described';
}

/** What the reward is and how it is delivered; cash is always paid outside WeavDash. */
export function rewardKind(terms: Pick<IncentiveTerms, 'reward_type'>): string {
    return terms.reward_type === 'cash'
        ? 'Cash per award · paid outside WeavDash'
        : `${rewardKinds[terms.reward_type]} · delivered outside WeavDash`;
}

/** The qualifying rule in plain words, shown the same to both sides. */
export function ruleLines(
    terms: Pick<IncentiveTerms, 'rule_stage' | 'leads_per_award' | 'starts_on' | 'ends_on'> & {
        campaign_title?: string | null;
    },
    stageLabel: (stage: string) => string,
): string[] {
    const leads =
        terms.leads_per_award === 1 ? 'Each registered lead' : `Every ${terms.leads_per_award} registered leads`;
    return [
        `${leads} that reaches ${stageLabel(terms.rule_stage)} or later`,
        terms.campaign_title ? `Source campaign: ${terms.campaign_title}` : 'Any registered lead counts',
        terms.starts_on && terms.ends_on
            ? `Claims from ${calendarDate(terms.starts_on)} to ${calendarDate(terms.ends_on)}`
            : 'Dates not set',
        'The Brand approves every award',
    ];
}

export function totals(list: MoneyTotal[]): string {
    return list.length ? list.map((item) => formatMoney(item.minor, item.currency)).join(' + ') : '0';
}

export const incentiveStates: Record<IncentiveState, { label: string; tone: BadgeTone }> = {
    draft: { label: 'Private draft', tone: 'neutral' },
    shared: { label: 'Active', tone: 'success' },
    ended: { label: 'Ended', tone: 'neutral' },
};

export const awardStates: Record<AwardState, { label: string; tone: BadgeTone }> = {
    pending: { label: 'In review', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    fulfilled: { label: 'Fulfilled', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

export const cancelReasons = {
    withdrawn: 'A cited lead was withdrawn.',
    source_deleted: 'A cited lead was deleted.',
    partnership_ended: 'The relationship ended.',
} as const;

export const candidateReasons = {
    used: 'Already used in an award for this incentive',
    stage: 'Stage does not meet the rule yet',
    campaign: 'Source campaign does not match',
} as const;

/** Whole days between two instants, for the late-link flag. */
export function daysBetween(from: string, to: string): number {
    return Math.floor((new Date(to).getTime() - new Date(from).getTime()) / 86_400_000);
}

/** A link set more than this many days after registering is flagged (mockup decision 5). */
export const LATE_LINK_DAYS = 7;
