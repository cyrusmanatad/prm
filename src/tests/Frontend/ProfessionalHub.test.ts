import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Professional from '../../resources/js/pages/Professional.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/professional', props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { template: '<span />' },
    router: { post: vi.fn() },
}));

const NOW = new Date('2026-11-21T12:00:00Z');
const wrappers: VueWrapper[] = [];
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
});

const summary = {
    leads: { open: 14, stages: { lead: 5, pitched: 4, negotiating: 3, onboarded: 2 } },
    reminders: { overdue: 2, dueDay: 1, dueWeek: 4 },
    onboarding: { submitted: 1, correction: 1 },
    invoices: { drafts: 3, dueWeek: 1 },
    payments: 2,
    templates: 4,
    followUps: 1,
    profilePublished: true,
};
const upNext = {
    reminderTotal: 3,
    onboardingTotal: 1,
    reminders: [
        { id: 'r1', title: 'Send revised proposal', due_at: '2026-11-19T09:00:00Z', lead: 'Harbor Studio' },
        { id: 'r2', title: 'Follow up on call', due_at: '2026-11-21T08:00:00Z', lead: null },
        { id: 'r3', title: 'Confirm kickoff', due_at: '2026-11-22T09:00:00Z', lead: 'Atlas Fitness' },
    ],
    onboarding: [{ id: 'o1', label: 'Website intake', lead: 'Northline Bakery', submitted_at: '2026-11-20T10:00:00Z' }],
};
function hub(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(Professional, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { name: 'Studio' },
            subscription: {
                state: 'active',
                plan: { name: 'Pilot plan', starts_at: '2026-11-01T00:00:00Z', ends_at: '2027-01-31T00:00:00Z' },
                usage: [
                    { resource: 'pipeline_entries', used: 16, limit: 50 },
                    { resource: 'reminders', used: 41, limit: 50 },
                    { resource: 'manual_payments', used: 50, limit: 50 },
                ],
            },
            isOwner: true,
            canWrite: true,
            summary,
            upNext,
            ...overrides,
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('summarizes open leads, due reminders, onboarding and owner finance with links into each tool', () => {
    const wrapper = hub();
    const tiles = wrapper.findAll('.hub-stats a');
    expect(tiles.map((tile) => tile.attributes('href'))).toEqual([
        '/professional/leads',
        '/professional/contacts?state=pending',
        '/professional/onboarding?status=submitted',
        '/professional/finance/invoices',
    ]);
    expect(tiles[0]!.text()).toContain('14');
    expect(tiles[0]!.text()).toContain('5 lead · 4 pitched · 3 negotiating · 2 onboarded');
    expect(tiles[1]!.text()).toContain('3');
    expect(tiles[1]!.text()).toContain('2 overdue · 1 within 24 hours · 4 this week');
    expect(tiles[2]!.text()).toContain('1 waiting on client corrections');
    expect(tiles[3]!.text()).toContain('Owner only · 1 due this week');
    expect(wrapper.get('.hub-primary').attributes('href')).toBe('/professional/leads?create=1');
});

it('orders Up next by urgency and filters it without losing totals', async () => {
    const wrapper = hub();
    const rows = () => wrapper.findAll('.hub-queue a');
    expect(rows().map((row) => row.get('strong').text())).toEqual([
        'Send revised proposal',
        'Follow up on call',
        'Website intake',
        'Confirm kickoff',
    ]);
    expect(rows()[0]!.text()).toContain('Overdue ·');
    expect(rows()[0]!.attributes('href')).toBe('/professional/contacts?state=due&q=Send%20revised%20proposal');
    expect(rows()[2]!.attributes('href')).toBe('/professional/onboarding?request=o1');
    expect(rows()[3]!.attributes('href')).toBe('/professional/contacts?state=pending&q=Confirm%20kickoff');
    expect(wrapper.get('.hub-card-foot').text()).toContain('Showing 4 of 4');

    const onboarding = wrapper
        .findAll('.wd-filter-chips button')
        .find((button) => button.text().startsWith('Onboarding'))!;
    await onboarding.trigger('click');
    expect(rows()).toHaveLength(1);
    expect(wrapper.get('.hub-card-foot').text()).toContain('Showing 1 of 1');
    expect(wrapper.get('.hub-card-foot a').attributes('href')).toBe('/professional/onboarding?status=submitted');
});

it('shows count pills, the published profile and usage warnings', () => {
    const wrapper = hub();
    const tool = (label: string) => wrapper.findAll('.hub-tool-group a').find((link) => link.text().includes(label))!;
    expect(tool('Contacts and reminders').get('.hub-count').text()).toBe('2 overdue');
    expect(tool('Contacts and reminders').get('.hub-count').classes()).toContain('is-hot');
    expect(tool('Client onboarding').get('.hub-count').text()).toBe('1 to review');
    expect(tool('Automation and approvals').get('.hub-count').text()).toBe('1 to review');
    expect(tool('Profile / portfolio').text()).toContain('Published');
    expect(tool('Brand sharing').attributes('href')).toBe('/professional/brand-sharing');
    const usage = wrapper.findAll('.hub-usage li');
    expect(usage[0]!.classes()).toEqual([]);
    expect(usage[1]!.classes()).toContain('is-near');
    expect(usage[2]!.classes()).toContain('is-full');
    expect(usage[2]!.text()).toContain('50 / 50 · limit reached');
});

it('hides finance from members and adding leads while writes are paused', () => {
    const wrapper = hub({
        isOwner: false,
        canWrite: false,
        summary: { ...summary, invoices: null, payments: null },
        subscription: { state: 'expired', plan: null, usage: [] },
    });
    expect(wrapper.findAll('.hub-stats li')).toHaveLength(3);
    expect(wrapper.find('.hub-primary').exists()).toBe(false);
    expect(wrapper.text()).toContain('Finance is available only to the workspace owner.');
    expect(wrapper.find('a[href="/professional/finance/invoices"]').exists()).toBe(false);
    expect(wrapper.findAll('a[href^="/professional/exports/"]').map((link) => link.attributes('aria-label'))).toEqual([
        'Export leads as CSV',
        'Export contacts as CSV',
        'Export reminders as CSV',
    ]);
    expect(wrapper.get('.hub-plan-message').classes()).toContain('is-danger');
    expect(wrapper.text()).toContain('Contact your administrator to renew access.');
});

it('shows an empty Up next state for a new workspace', () => {
    const wrapper = hub({ upNext: { reminderTotal: 0, onboardingTotal: 0, reminders: [], onboarding: [] } });
    expect(wrapper.find('.hub-queue').exists()).toBe(false);
    expect(wrapper.get('.wd-empty-state').text()).toContain('Nothing due');
});
