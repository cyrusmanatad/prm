import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import HomeView from '../../resources/js/features/home/HomeView.vue';
import type { BrandHome, CreatorHome, HomeStage } from '../../resources/js/features/home/types';

const router = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/partner/home', props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
}));
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    window.localStorage.clear();
});

const stageNames = ['Lead', 'Pitched', 'Negotiating', 'Onboarded', 'Active', 'Repeat Client', 'Lost'];
const stages = (counts: number[], totals: HomeStage['totals'][] = []): HomeStage[] =>
    stageNames.map((label, index) => ({
        value: label.toLowerCase().replace(' ', '-'),
        label,
        count: counts[index] ?? 0,
        totals: totals[index] ?? [],
    }));

function creator(overrides: Partial<CreatorHome> = {}): CreatorHome {
    return {
        clock: new Date().toISOString(),
        tasks: {
            reminders: [
                { id: 'r1', title: 'Call back', due_at: '2020-01-01T00:00:00Z', lead: 'Cedar Coffee' },
                { id: 'r2', title: 'Send deck', due_at: '2999-01-01T00:00:00Z', lead: null },
            ],
            reminderTotal: 9,
            onboarding: [{ id: 'o1', label: 'Intake', lead: 'Lumen Skin', submitted_at: '2026-11-20T00:00:00Z' }],
            onboardingTotal: 1,
            leadsDue: 2,
        },
        metrics: {
            openLeads: 5,
            openValue: [
                { currency: 'PHP', amount_minor: '1234500' },
                { currency: 'USD', amount_minor: '9900' },
            ],
            remindersDue: 4,
            onboardingReview: 1,
            partnerships: 3,
        },
        stages: stages([2, 1, 0, 1, 1, 0, 3], [[{ currency: 'PHP', amount_minor: '1000000' }]]),
        recent: [
            {
                id: 'e1',
                name: 'Cedar Coffee',
                stage: 'negotiating',
                currency: 'PHP',
                value_minor: '1000000',
                next_action_due: null,
                updated_at: '2026-11-23T00:00:00Z',
            },
        ],
        activity: [
            {
                id: 'a1',
                kind: 'stage',
                entry_id: 'e1',
                name: 'Cedar Coffee',
                body: 'Stage changed from pitched to negotiating.',
                occurred_at: '2026-11-23T00:00:00Z',
            },
            { id: 'a2', kind: 'note', entry_id: 'e1', name: 'Cedar Coffee', body: '', occurred_at: null },
        ],
        profilePublished: false,
        ...overrides,
    };
}

function brand(overrides: Partial<BrandHome> = {}): BrandHome {
    return {
        clock: new Date().toISOString(),
        tasks: { pendingInvitations: null, updatedWeek: 1, registeredRecent: 0 },
        metrics: { activePartners: 4, leadsRegistered: 6, registeredRecent: 0, partnersWithLeads: 2 },
        stages: stages([6]),
        recent: [],
        activity: [
            {
                id: 'g1',
                display_name: 'Harbor Hotel',
                stage: 'lead',
                partner_name: 'Cedar Creator',
                registered_at: '2026-11-20T00:00:00Z',
                shared_updated_at: '2026-11-22T00:00:00Z',
                event: 'updated',
            },
        ],
        canManage: false,
        ...overrides,
    };
}

function view(props: Record<string, unknown>) {
    const wrapper = mount(HomeView, { props: { workspaceName: 'Studio', ...props } as never, attachTo: document.body });
    wrappers.push(wrapper);
    return wrapper;
}

it('shows Partner tasks, per-currency totals and all seven stored stages without inventing figures', () => {
    const wrapper = view({ experience: 'partner', home: creator() });
    const tasks = wrapper.findAll('.wd-home-task');
    expect(tasks.map((task) => task.attributes('href'))).toEqual([
        '/partner/contacts?state=due',
        '/partner/contacts?state=due',
        '/partner/onboarding?request=o1',
        '/partner/leads?due=1',
    ]);
    expect(tasks[0]!.classes()).toContain('is-urgent');
    expect(tasks[0]!.text()).toContain('Overdue');
    expect(wrapper.text()).toContain('View all due reminders (9)');
    // Different currencies are listed side by side, never added together.
    expect(wrapper.text()).toContain('PHP 12,345.00 · USD 99.00');
    expect(wrapper.text()).toContain('Brand partnerships');
    expect(wrapper.findAll('.wd-home-stage-list li')).toHaveLength(7);
    expect(wrapper.get('.wd-home-stage-bar').attributes('aria-label')).toBe(
        'Leads by stage: Lead 2, Pitched 1, Negotiating 0, Onboarded 1, Active 1, Repeat Client 0, Lost 3',
    );
    expect(wrapper.get('.wd-home-table a').attributes('href')).toBe('/partner/leads?entry=e1');
    expect(wrapper.text()).not.toMatch(/vs last quarter|Build with AI|unread/i);
    // The prompt points at the existing media-kit lead link.
    expect(wrapper.get('.wd-home-prompt a').attributes('href')).toBe('/partner/media-kit');
});

it('switches between implemented views with the keyboard and keeps Feed explicitly unavailable', async () => {
    const wrapper = view({ experience: 'partner', home: creator() });
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs.map((tab) => tab.text())).toEqual(['Analytics', 'Activity']);
    expect(wrapper.get('.wd-home-unavailable-tab').attributes('aria-disabled')).toBe('true');
    expect(wrapper.get('.wd-home-unavailable-tab').text()).toBe('Feed, coming Soon');
    await tabs[0]!.trigger('keydown', { key: 'ArrowRight' });
    await nextTick();
    expect(tabs[1]!.attributes('aria-selected')).toBe('true');
    expect(document.activeElement).toBe(tabs[1]!.element);
    const activity = wrapper.get('#home-views-panel-activity');
    expect(activity.isVisible()).toBe(true);
    expect(activity.text()).toContain('Cedar Coffee: Stage Pitched → Negotiating');
    // Logged conversations name their kind only; their text stays in the lead drawer.
    expect(activity.text()).toContain('Cedar Coffee: Note logged');
});

it('refreshes only the Home data and dismisses the prompt as a display preference', async () => {
    const wrapper = view({
        experience: 'professional',
        home: creator({ profilePublished: true, metrics: { ...creator().metrics, partnerships: null } }),
    });
    expect(wrapper.get('.wd-home-prompt h2').text()).toBe('Share your client inquiry link');
    expect(wrapper.get('.wd-home-prompt a').attributes('href')).toBe('/professional/profile');
    expect(wrapper.text()).not.toContain('Brand partnerships');
    expect(wrapper.findAll('.wd-home-task')[0]!.attributes('href')).toBe('/professional/contacts?state=due');
    await wrapper.get('button[class*="wd-home-icon-button"]:not(.wd-home-dismiss)').trigger('click');
    expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['home'] }));
    await wrapper.get('.wd-home-dismiss').trigger('click');
    expect(wrapper.find('.wd-home-prompt').exists()).toBe(false);
    expect(window.localStorage.getItem('weavdash.home.prompt.professional')).toBe('dismissed');
});

it('labels Brand figures as relationships or registrations and hides invitation figures from other roles', async () => {
    const wrapper = view({ experience: 'brand', home: brand() });
    const metrics = wrapper.findAll('.wd-stat-card h3').map((heading) => heading.text());
    expect(metrics).toEqual(['Active partners', 'Registered leads', 'Registered (30 days)', 'Partners with leads']);
    expect(wrapper.text()).not.toContain('invitation');
    // Non-managers get no invitation prompt.
    expect(wrapper.find('.wd-home-prompt').exists()).toBe(false);
    expect(wrapper.text()).toContain('Registered leads by stage');
    expect(wrapper.find('.wd-home-stage-value').exists()).toBe(false);
    expect(wrapper.text()).toContain('Leads your partners register with you will appear here.');
    expect(wrapper.get('.wd-home-task').attributes('href')).toBe('/brand/lead-registrations');

    const manager = view({
        experience: 'brand',
        home: brand({ canManage: true, tasks: { pendingInvitations: 2, updatedWeek: 0, registeredRecent: 3 } }),
    });
    expect(manager.findAll('.wd-home-task').map((task) => task.text())).toEqual([
        '2 partner invitations awaiting acceptance',
        '3 leads registered in the last 30 days',
    ]);
    expect(manager.get('.wd-home-prompt a').attributes('href')).toBe('/brand');
    await manager.findAll('[role="tab"]')[1]!.trigger('click');
    expect(manager.get('#home-views-panel-activity').text()).toContain(
        'Cedar Creator updated shared details for Harbor Hotel',
    );
});
