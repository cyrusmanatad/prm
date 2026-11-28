import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import PartnerIncentives from '../../resources/js/features/incentives/PartnerIncentives.vue';
import type { PartnerOffer } from '../../resources/js/features/incentives/types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (data: Record<string, unknown>) => {
            const initial = JSON.parse(JSON.stringify(data));
            const form = reactive({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                post,
                reset: () => Object.assign(form, JSON.parse(JSON.stringify(initial))),
                clearErrors: () => (form.errors = {}),
                setError: (key: string, message: string) => (form.errors = { ...form.errors, [key]: message }),
            });
            return form;
        },
    };
});

const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    post.mockReset();
});

function offer(change: Partial<PartnerOffer> = {}): PartnerOffer {
    return {
        id: 'i1',
        title: 'Onboarding bonus',
        terms: 'Paid within 30 days.',
        reward_type: 'cash',
        amount_minor: '200000',
        currency: 'PHP',
        reward_text: '',
        rule_stage: 'onboarded',
        leads_per_award: 1,
        starts_on: '2026-11-01',
        ends_on: '2027-02-28',
        state: 'shared',
        brand_name: 'Habi Home',
        partnership_id: 'p1',
        campaign_id: null,
        campaign_title: null,
        cap_per_partner: 2,
        granted: 0,
        pending: 0,
        has_room: true,
        open: true,
        ready: 1,
        candidates: [
            { id: 'r1', name: 'Ready lead', stage: 'onboarded', campaign_title: null, eligible: true, reason: null },
            { id: 'r2', name: 'Second lead', stage: 'active', campaign_title: null, eligible: true, reason: null },
            { id: 'r3', name: 'Early lead', stage: 'pitched', campaign_title: null, eligible: false, reason: 'stage' },
        ],
        ...change,
    };
}

function show(offers: PartnerOffer[]) {
    HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
        this.setAttribute('open', '');
    };
    const wrapper = mount(PartnerIncentives, {
        attachTo: document.body,
        props: {
            canWrite: true,
            offers,
            awards: [],
            figures: { offers: offers.length, review: 0, approved: 0, received: 0 },
            stageLabel: (stage: string) => stage,
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('explains why an offer cannot be claimed and never hides the total cap reason', () => {
    const wrapper = show([
        offer({ id: 'a', ready: 0 }),
        offer({ id: 'b', has_room: false }),
        offer({ id: 'c', granted: 1, pending: 1 }),
    ]);
    const footers = wrapper.findAll('.ic-offer footer').map((footer) => footer.text());
    expect(footers[0]).toContain('Needs 1 qualifying lead');
    expect(footers[0]).toContain('0 of 1 ready');
    expect(footers[1]).toContain('No awards left on this offer');
    expect(footers[2]).toContain('Your award limit is reached');
    wrapper.findAll('.ic-offer .ic-claim').forEach((button) => expect(button.attributes('disabled')).toBeDefined());
    expect(wrapper.text()).not.toContain('cap_total');
});

it('summarizes the offers, shows lead progress and ties a locked Claim button to its reason', () => {
    const wrapper = show([offer({ id: 'a', ready: 1, leads_per_award: 2 }), offer({ id: 'b', ready: 3 })]);
    expect(wrapper.get('.ic-offers-head').text()).toContain('2 incentives from');
    const [locked, open] = wrapper.findAll('.ic-offer');
    const bar = locked!.get('.ic-ready-bar');
    expect([bar.attributes('aria-valuenow'), bar.attributes('aria-valuemax')]).toEqual(['1', '2']);
    expect(locked!.get('.ic-ready').text()).toContain('1 of 2 ready');
    const button = locked!.get('.ic-claim');
    expect(button.attributes('disabled')).toBeDefined();
    expect(wrapper.get(`#${button.attributes('aria-describedby')}`).text()).toContain('Needs 2 qualifying leads');
    expect(open!.get('.ic-ready').text()).toContain('3 leads ready');
    expect(open!.get('.ic-ready').text()).toContain('Ready to claim');
    expect(open!.get('.ic-claim').attributes('disabled')).toBeUndefined();
});

it('limits a claim to the leads per award and keeps ineligible leads disabled with a reason', async () => {
    const wrapper = show([offer()]);
    await wrapper.get('.ic-offer .ic-claim').trigger('click');
    await flushPromises();
    const boxes = () => wrapper.findAll<HTMLInputElement>('.ic-claim-leads input[type="checkbox"]');
    expect(wrapper.get('.ic-claim-leads').text()).toContain('Stage does not meet the rule yet');
    expect(boxes()[2]!.element.disabled).toBe(true);
    await boxes()[0]!.setValue(true);
    expect(wrapper.get('#claim-count').text()).toBe('1 of 1 selected');
    expect(boxes()[1]!.element.disabled).toBe(true);
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledWith('/partner/incentives/i1/claims', expect.anything());
});
