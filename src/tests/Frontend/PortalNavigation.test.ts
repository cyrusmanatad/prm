import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import PortalShell from '../../resources/js/layouts/PortalShell.vue';
import AccountMenu from '../../resources/js/components/AccountMenu.vue';
import {
    activePortalHref,
    isPortalPage,
    isUnavailable,
    portalGroups,
    type PortalExperience,
} from '../../resources/js/features/portals/portalNavigation';

const page = reactive<{ url: string; props: Record<string, unknown> }>({ url: '/partner/home', props: {} });
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), get: vi.fn() },
}));

const wrappers: VueWrapper[] = [];
beforeEach(() => {
    window.localStorage.clear();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
    page.url = '/partner/home';
    page.props = {};
    document.body.innerHTML = '';
});

const labels = (experience: PortalExperience, owner: boolean) =>
    portalGroups(experience, owner).map((group) => ({
        group: group.label ?? '',
        items: group.items.map((item) => (isUnavailable(item) ? `${item.label} (unavailable)` : item.label)),
    }));

it('defines each experience menu with Home first and every existing unavailable entry retained', () => {
    expect(labels('brand', true)).toEqual([
        { group: '', items: ['Home'] },
        { group: 'Partners', items: ['Partner hub', 'Lead registrations'] },
        {
            group: 'Programs',
            items: ['Campaigns', 'Incentives (unavailable)', 'Enablement (unavailable)', 'Payouts (unavailable)'],
        },
    ]);
    const partner = labels('partner', true).flatMap((group) => group.items);
    expect(partner[0]).toBe('Home');
    for (const tool of [
        'Campaigns',
        'My leads',
        'Contacts and reminders',
        'Onboarding requests',
        'Brand sharing',
        'Outreach templates',
        'Automation and approvals',
        'Media kit',
        'Private invoice drafts',
        'Manual payment records',
        'Pilot entitlements',
    ])
        expect(partner).toContain(tool);
    expect(partner.filter((label) => label.endsWith('(unavailable)'))).toEqual([
        'Inbox (unavailable)',
        'Incentives (unavailable)',
        'Autopilot (unavailable)',
        'Earnings (unavailable)',
    ]);
    // Partner programs mirror the Brand group.
    expect(labels('partner', true).find((group) => group.group === 'Programs')?.items).toEqual([
        'Campaigns',
        'Incentives (unavailable)',
    ]);
    // Owner-only finance tools are hidden from other roles; the unavailable Earnings entry stays.
    const member = labels('partner', false).flatMap((group) => group.items);
    expect(member).not.toContain('Private invoice drafts');
    expect(member).not.toContain('Pilot entitlements');
    expect(member).toContain('Earnings (unavailable)');
    // Professional keeps all ten existing tools after Home and gains no unavailable entries.
    const professional = labels('professional', false).flatMap((group) => group.items);
    expect(professional).toHaveLength(11);
    expect(professional.slice(0, 2)).toEqual(['Home', 'Professional Hub']);
    expect(professional.some((label) => label.endsWith('(unavailable)'))).toBe(false);
});

it('marks one current item for list, detail, alias, query and fragment addresses', () => {
    for (const [experience, url, expected] of [
        ['brand', '/brand/home', '/brand/home'],
        ['brand', '/brand', '/brand'],
        ['brand', '/brand?page=2&q=cedar', '/brand'],
        ['brand', '/brand/partners/7f0c', '/brand'],
        ['brand', '/brand/lead-registrations/abc?stage=lead#top', '/brand/lead-registrations'],
        ['brand', '/brand/requests', null],
        ['brand', '/finance/invoices', null],
        ['partner', '/partner/home', '/partner/home'],
        ['partner', '/partner', '/partner/leads'],
        ['partner', '/partner/leads?entry=abc', '/partner/leads'],
        ['partner', '/pipeline', '/partner/leads'],
        ['partner', '/crm?state=due', '/partner/contacts'],
        ['partner', '/partner/onboarding?request=x', '/partner/onboarding'],
        ['partner', '/finance/invoices/1234-uuid', '/finance/invoices'],
        ['partner', '/automation#schedules', '/automation'],
        ['partner', '/partner/homework', null],
        ['partner', '/workspaces', null],
        ['professional', '/professional', '/professional'],
        ['professional', '/professional/home', '/professional/home'],
        ['professional', '/partner/contacts?entry=x', '/professional/contacts'],
        ['professional', '/partner/media-kit', '/professional/profile'],
        ['professional', '/finance/invoices/1234', '/professional/finance/invoices'],
        ['professional', '/automation', '/professional/automation'],
        ['professional', '/admin', null],
    ] as const)
        expect(activePortalHref(experience, url), `${experience} ${url}`).toBe(expected);
    // Home and the existing hub are distinct destinations.
    expect(activePortalHref('professional', '/professional/home')).not.toBe('/professional');
    expect(isPortalPage('partner', '/account/exports')).toBe(false);
    expect(isPortalPage('partner', '/workspace/entitlements')).toBe(true);
});

function shell(experience: PortalExperience = 'partner', props: Record<string, unknown> = {}) {
    const wrapper = mount(PortalShell, {
        props: { experience, accountName: 'Bea Santos', workspaceName: 'Bea Studio', ...props },
        slots: { default: '<p class="body">Body</p>' },
        attachTo: document.body,
        global: { stubs: { AccountMenu: true } },
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('renders one main landmark, the workspace identity and accessible unavailable rows', () => {
    page.url = '/partner/leads?entry=abc';
    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner', name: 'Bea Studio', role: 'member' } };
    const wrapper = shell();
    expect(wrapper.findAll('main#main-content')).toHaveLength(1);
    expect(wrapper.get('.wd-skip-link').attributes('href')).toBe('#main-content');
    // The experience stays with the branding; the workspace name moves to the account button (v2).
    expect(wrapper.get('.wd-portal-identity').text()).toBe('Partner');
    expect(wrapper.get('account-menu-stub').attributes('workspacelabel')).toBe('Bea Studio');
    const nav = wrapper.get('.wd-portal-sidebar nav[aria-label="Partner tools"]');
    expect(nav.findAll('[aria-current="page"]').map((item) => item.text())).toEqual(['My leads']);
    const unavailable = nav.findAll('[aria-disabled="true"]');
    expect(unavailable.map((item) => item.text())).toEqual([
        'Inbox, Not available yet',
        'Incentives, Not available yet',
        'Autopilot, Not available yet',
        'Earnings, Not available yet',
    ]);
    // Unavailable rows are never links or navigation requests.
    for (const item of unavailable) {
        expect(item.element.tagName).toBe('SPAN');
        expect(item.attributes('href')).toBeUndefined();
        expect(item.attributes('tabindex')).toBeUndefined();
    }
    // A member, not an owner: the server-authorized owner finance tools are not offered.
    expect(nav.find('a[href="/finance/invoices"]').exists()).toBe(false);
});

it('remembers the collapsed preference and keeps labels and explanations for assistive technology', async () => {
    const wrapper = shell();
    const toggle = wrapper.get('.wd-portal-collapse');
    expect(toggle.attributes('aria-expanded')).toBe('true');
    expect(toggle.text()).toContain('Collapse');
    await toggle.trigger('click');
    expect(window.localStorage.getItem('weavdash.portal.sidebar')).toBe('collapsed');
    expect(wrapper.get('.wd-portal').classes()).toContain('is-collapsed');
    expect(toggle.attributes('aria-expanded')).toBe('false');
    const nav = wrapper.get('.wd-portal-sidebar nav');
    // Labels stay in the accessible name; unavailable rows become focusable so their explanation is reachable.
    expect(nav.get('a[href="/partner/home"]').text()).toBe('Home');
    expect(nav.get('a[href="/partner/home"] .wd-portal-nav-label').classes()).toContain('wd-sr-only');
    const inbox = nav.findAll('[aria-disabled="true"]')[0]!;
    expect(inbox.attributes('tabindex')).toBe('0');
    expect(inbox.text()).toBe('Inbox, Not available yet');

    wrappers.splice(0).forEach((item) => item.unmount());
    const restored = shell();
    await nextTick();
    expect(restored.get('.wd-portal').classes()).toContain('is-collapsed');
});

it('falls back to the expanded sidebar when browser storage is unavailable', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('blocked');
    });
    const wrapper = shell();
    await nextTick();
    expect(wrapper.get('.wd-portal').classes()).not.toContain('is-collapsed');
    await wrapper.get('.wd-portal-collapse').trigger('click');
    expect(wrapper.get('.wd-portal').classes()).toContain('is-collapsed');
});

it('opens the mobile drawer as a labelled modal dialog, closes on Escape and restores focus', async () => {
    page.url = '/brand/lead-registrations';
    const wrapper = shell('brand', { tone: 'light' });
    const trigger = wrapper.get('.wd-portal-menu-button');
    expect(trigger.text()).toBe('Menu');
    (trigger.element as HTMLButtonElement).focus();
    await trigger.trigger('click');
    await flushPromises();
    const dialog = document.body.querySelector<HTMLElement>('[role="dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog!.textContent).toContain('Brand menu');
    expect(dialog!.textContent).toContain('Bea Studio');
    expect(dialog!.querySelector('[aria-current="page"]')?.textContent).toBe('Lead registrations');
    expect([...dialog!.querySelectorAll('[aria-disabled="true"]')].map((item) => item.textContent)).toHaveLength(3);
    expect(dialog!.contains(document.activeElement)).toBe(true);
    dialog!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await flushPromises();
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(trigger.element);
});

it('closes the drawer after a navigation completes', async () => {
    const wrapper = shell();
    await wrapper.get('.wd-portal-menu-button').trigger('click');
    await flushPromises();
    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
    page.url = '/partner/contacts';
    await flushPromises();
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
});

it('leaves workspace tools to the sidebar inside the portal shell', async () => {
    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner' } };
    const outside = mount(AccountMenu, { props: { accountName: 'Bea Santos' }, attachTo: document.body });
    wrappers.push(outside);
    await outside.get('button').trigger('keydown', { key: 'Enter' });
    await nextTick();
    expect(document.body.textContent).toContain('Contacts and reminders');
    outside.unmount();
    wrappers.splice(0);

    const inside = mount(AccountMenu, {
        props: { accountName: 'Bea Santos' },
        attachTo: document.body,
        global: { provide: { portalShell: true } },
    });
    wrappers.push(inside);
    await inside.get('button').trigger('keydown', { key: 'Enter' });
    await nextTick();
    const items = [...document.body.querySelectorAll('[role="menuitem"]')].map((item) => item.textContent?.trim());
    expect(items).toEqual(['Workspace / Account security', 'Privacy exports', 'Appearance', 'Sign out']);
});

it('places page search between the branding and the account controls, and keeps actions on the right', () => {
    const wrapper = mount(PortalShell, {
        props: { experience: 'partner', accountName: 'Bea Santos', workspaceName: 'Bea Studio' },
        slots: {
            default: '<p>Body</p>',
            search: '<div role="search" class="wd-portal-search"><input aria-label="Search leads" /></div>',
            actions: '<button type="button" class="page-action">Invite</button>',
        },
        attachTo: document.body,
        global: { stubs: { AccountMenu: true } },
    });
    wrappers.push(wrapper);
    const row = [...wrapper.get('.wd-portal-header-inner').element.children].map((child) => child.className);
    expect(row.slice(-3)).toEqual(['wd-portal-masthead', 'wd-portal-search-slot', 'wd-portal-actions']);
    expect(wrapper.find('.wd-portal-search-slot [role="search"]').exists()).toBe(true);
    expect(wrapper.get('.wd-portal-actions').find('[role="search"]').exists()).toBe(false);
    expect(wrapper.get('.wd-portal-actions .page-action').text()).toBe('Invite');
    expect(wrapper.find('.wd-portal-actions account-menu-stub').exists()).toBe(true);

    // Without a search slot nothing reserves the middle of the header.
    expect(shell('brand').find('.wd-portal-search-slot').exists()).toBe(false);
});

it('labels the account button with the selected workspace and follows a workspace switch', async () => {
    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner', name: 'First Studio', role: 'owner' } };
    const wrapper = mount(PortalShell, {
        props: { experience: 'partner', accountName: 'Bea Santos' },
        slots: { default: '<p>Body</p>' },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    const trigger = () => wrapper.get('button.wd-account-trigger');
    expect(trigger().classes()).toContain('wd-account-trigger-labelled');
    expect(trigger().get('.wd-account-workspace').text()).toBe('First Studio');
    expect(trigger().get('.wd-account-workspace').attributes('title')).toBe('First Studio');
    expect(trigger().text()).toBe('Account menu: First StudioBS');

    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner', name: 'Second Studio', role: 'owner' } };
    await nextTick();
    expect(trigger().get('.wd-account-workspace').text()).toBe('Second Studio');
    expect(wrapper.get('.wd-portal-drawer-workspace, .wd-portal-identity').text()).not.toContain('First Studio');

    // No selected workspace: the avatar-only button returns, with no stale or sample name.
    page.props = { activeWorkspace: null };
    await nextTick();
    expect(trigger().find('.wd-account-workspace').exists()).toBe(false);
    expect(trigger().classes()).not.toContain('wd-account-trigger-labelled');

    // The labelled button still opens the same account menu.
    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner', name: 'Second Studio', role: 'owner' } };
    await nextTick();
    await trigger().trigger('keydown', { key: 'Enter' });
    await nextTick();
    const items = [...document.body.querySelectorAll('[role="menuitem"]')].map((item) => item.textContent?.trim());
    expect(items).toEqual(['Workspace / Account security', 'Privacy exports', 'Appearance', 'Sign out']);
});
