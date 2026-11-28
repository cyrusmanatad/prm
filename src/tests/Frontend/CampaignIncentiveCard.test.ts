import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import CampaignRecord from '../../resources/js/features/campaigns/CampaignRecord.vue';
import type { CampaignDetail } from '../../resources/js/features/campaigns/types';
import type { Offer, PartnerOffer } from '../../resources/js/features/incentives/types';

const visit = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { visit: (...args: unknown[]) => visit(...args) },
        useForm: (data: Record<string, unknown>) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
    };
});
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    visit.mockReset();
});

const campaign: CampaignDetail = {
    id: 'c1',
    title: 'Winter collection',
    due_date: '2027-02-03',
    partnership_id: 'p1',
    created_at: '2026-11-01T00:00:00Z',
    shared_at: '2026-11-02T00:00:00Z',
    brand_name: 'Winter Studio',
    creator_name: 'Roster Partner',
    status: 'shared',
    leads: 0,
    incentive: { id: 'i1', title: 'Winter bonus', state: 'shared' },
    brief: 'Photograph three pieces.',
    version: 2,
};
const terms = {
    id: 'i1',
    title: 'Winter bonus',
    terms: 'Paid within 30 days.',
    reward_type: 'cash' as const,
    amount_minor: '150000',
    currency: 'PHP',
    reward_text: '',
    rule_stage: 'onboarded',
    leads_per_award: 1,
    starts_on: '2026-11-28',
    ends_on: '2027-12-31',
    state: 'shared' as const,
    campaign_id: 'c1',
    campaign_title: 'Winter collection',
    cap_per_partner: null,
    granted: 2,
    pending: 1,
};
function show(brand: boolean, incentive: Offer | PartnerOffer, canManage = true) {
    const wrapper = mount(CampaignRecord, {
        props: { campaign, brand, canManage, leads: [], incentive, stages: [] },
        global: { stubs: { FormDialog: true, RecordHeader: true, Breadcrumbs: true } },
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('shows the Brand the card its Partner sees, with an inert Claim button and its own counts', () => {
    const offer: Offer = {
        ...terms,
        version: 2,
        cap_total: null,
        created_at: '2026-11-28T00:00:00Z',
        shared_at: '2026-11-28T00:00:00Z',
        ended_at: null,
        audience: [{ id: 'p1', name: 'Roster Partner' }],
    };
    const wrapper = show(true, offer);
    const card = wrapper.get('.ic-offer');
    expect(card.text()).toContain('From Winter Studio');
    expect(card.get('.ic-claim').attributes('aria-hidden')).toBe('true');
    expect(card.find('button').exists()).toBe(false);
    expect(card.text()).toContain('Example for a Partner');
    expect(wrapper.get('a[href="/brand/incentives/i1"]').text()).toBe('Open incentive');
    expect(wrapper.text()).toContain('2 awards approved · 1 pending');
});

it('lets the Partner claim from the brief on the Incentives page, and only with the claim permission', async () => {
    const offer: PartnerOffer = {
        ...terms,
        brand_name: 'Winter Studio',
        partnership_id: 'p1',
        cap_per_partner: null,
        granted: 0,
        pending: 0,
        has_room: true,
        open: true,
        ready: 1,
        candidates: [],
    };
    const button = show(false, offer).get('.ic-offer button.ic-claim');
    expect(button.text()).toBe('Claim Winter bonus');
    await button.trigger('click');
    expect(visit).toHaveBeenCalledWith('/partner/incentives#offer-i1');
    expect(
        show(false, { ...offer, ready: 0 })
            .get('.ic-claim')
            .attributes('disabled'),
    ).toBeDefined();
    expect(show(false, offer, false).find('.ic-claim').exists()).toBe(false);
});
