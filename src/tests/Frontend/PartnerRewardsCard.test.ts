import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import PartnerDashboard from '../../resources/js/features/portals/PartnerDashboard.vue';
import type { PortalData } from '../../resources/js/features/portals/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ url: '/partner/leads', props: {} }),
}));
vi.mock('../../resources/js/composables/useWorkspaceExperience', async () => {
    const { ref } = await import('vue');
    return {
        useWorkspaceExperience: () => ({
            professional: ref(false),
            path: (value: string) => value,
            clientLabel: 'brand',
        }),
    };
});
const wrappers: VueWrapper[] = [];
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));

type Rewards = { earned: { currency: string; minor: string }[]; nonCash: number; awaiting: number };
function card(rewards?: Rewards) {
    const data = {
        account: { name: 'Jordan Vale', email: 'jordan@example.test' },
        workspace: { id: 'w', name: 'Workspace', kind: 'creator' },
        relationships: [],
        canManage: true,
        pipeline: { totals: [], filters: { q: '' }, canWrite: false, ...(rewards ? { rewards } : {}) },
    } as unknown as PortalData;
    const wrapper = mount(PartnerDashboard, {
        props: { data },
        global: {
            stubs: { PartnerChrome: { template: '<div><slot /></div>' }, LeadManager: true, PipelineBoard: true },
        },
    });
    wrappers.push(wrapper);
    const summary = wrapper.get('section[aria-label="Summary"]');
    return summary.findAll(':scope > div').find((item) => item.text().startsWith('Rewards earned'))!;
}

it('places Rewards earned beside Open pipeline in place of Reply rate', () => {
    const labels = card({ earned: [], nonCash: 0, awaiting: 0 }).element.parentElement!.querySelectorAll(
        ':scope > div > div:first-child',
    );
    expect([...labels].map((label) => label.textContent!.trim())).toEqual([
        'Open pipeline',
        'Rewards earned',
        'Expected this month',
        'Commissions earned',
    ]);
});

it('shows delivered cash per currency and counts non-cash rewards without summing them', () => {
    const rewards = card({
        earned: [
            { currency: 'PHP', minor: '1200000' },
            { currency: 'USD', minor: '30000' },
        ],
        nonCash: 2,
        awaiting: 1,
    });
    expect(rewards.text()).toContain('PHP 12,000.00 · USD 300.00');
    expect(rewards.text()).toContain('+ 2 non-cash rewards · 1 approved, awaiting delivery');
    expect(rewards.get('a').attributes('href')).toBe('/partner/incentives');
});

it('names non-cash rewards or an empty state when no cash was delivered', () => {
    expect(card({ earned: [], nonCash: 1, awaiting: 0 }).text()).toContain('1 non-cash reward');
    const empty = card().text();
    expect(empty).toContain('None yet');
    expect(empty).toContain('Incentive rewards your Brands have delivered');
});
