import { expect, it } from 'vitest';
import { daysBetween, rewardKind, rewardValue, ruleLines, totals } from '../../resources/js/features/incentives/format';

const label = (stage: string) => ({ onboarded: 'Onboarded', active: 'Active' })[stage] ?? stage;

it('shows cash as an amount paid outside WeavDash and other rewards as their description', () => {
    expect(rewardValue({ reward_type: 'cash', amount_minor: '200000', currency: 'PHP', reward_text: '' })).toContain(
        '2,000',
    );
    expect(rewardKind({ reward_type: 'cash' })).toBe('Cash per award · paid outside WeavDash');
    expect(
        rewardValue({ reward_type: 'non_cash', amount_minor: null, currency: null, reward_text: 'Starter bundle' }),
    ).toBe('Starter bundle');
    expect(rewardKind({ reward_type: 'percent' })).toBe('Percentage bonus · delivered outside WeavDash');
    expect(rewardValue({ reward_type: 'percent', amount_minor: null, currency: null, reward_text: '' })).toBe(
        'Reward not described',
    );
});

it('states the qualifying rule in the same words for both sides', () => {
    const terms = { rule_stage: 'onboarded', leads_per_award: 1, starts_on: '2026-12-01', ends_on: '2027-02-28' };
    expect(ruleLines(terms, label)).toEqual([
        'Each registered lead that reaches Onboarded or later',
        'Any registered lead counts',
        'Claims from 1 Dec 2026 to 28 Feb 2027',
        'The Brand approves every award',
    ]);
    expect(ruleLines({ ...terms, leads_per_award: 3, campaign_title: 'Winter launch' }, label).slice(0, 2)).toEqual([
        'Every 3 registered leads that reaches Onboarded or later',
        'Source campaign: Winter launch',
    ]);
});

it('sums money per currency and measures late links in whole days', () => {
    expect(totals([])).toBe('0');
    expect(totals([{ currency: 'PHP', minor: '150000' }])).toContain('1,500');
    expect(daysBetween('2026-11-01T10:00:00Z', '2026-11-09T09:00:00Z')).toBe(7);
    expect(daysBetween('2026-11-01T10:00:00Z', '2026-11-09T11:00:00Z')).toBe(8);
});
