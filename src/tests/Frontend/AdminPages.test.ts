import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive, type Component } from 'vue';
import PlatformAccount from '../../resources/js/pages/PlatformAccount.vue';
import PlatformAccounts from '../../resources/js/pages/PlatformAccounts.vue';
import PlatformAssign from '../../resources/js/pages/PlatformAssign.vue';
import PlatformNotification from '../../resources/js/pages/PlatformNotification.vue';
import PlatformOperations from '../../resources/js/pages/PlatformOperations.vue';
import PlatformOperators from '../../resources/js/pages/PlatformOperators.vue';
import PlatformOverview from '../../resources/js/pages/PlatformOverview.vue';
import PlatformWorkspaces from '../../resources/js/pages/PlatformWorkspaces.vue';
import PlatformCleanupRun from '../../resources/js/pages/PlatformCleanupRun.vue';
import PlatformSupportGrant from '../../resources/js/pages/PlatformSupportGrant.vue';
import WorkspaceSupport from '../../resources/js/pages/WorkspaceSupport.vue';
import AccessChange from '../../resources/js/features/admin/AccessChange.vue';
import UsageTable from '../../resources/js/features/admin/UsageTable.vue';
import { confirmation, type Confirmation } from '../../resources/js/composables/useConfirmation';

// Read through a function so TypeScript does not narrow the shared ref after a test clears it.
const pending = (): Confirmation | null => confirmation.value;

const { get, post, routerPost, page } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    routerPost: vi.fn(),
    page: { value: undefined as unknown as { url: string; props: Record<string, unknown> } },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: make } = await import('vue');
    return {
        usePage: () => page.value,
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<span />' },
        router: { get, post: routerPost, reload: vi.fn() },
        useForm: (data: Record<string, unknown>) => {
            const form = make({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                clearErrors: () => (form.errors = {}),
                reset: vi.fn(),
                transform: () => form,
                post,
            });
            return form;
        },
    };
});
const wrappers: VueWrapper[] = [];
beforeEach(() => {
    page.value = reactive({
        url: '/admin',
        props: { admin: { capabilities: ['platform.owner'], environment: 'Development', verified: true } },
    });
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmation.value = null;
    vi.clearAllMocks();
});
function render(component: Component, props: Record<string, unknown>) {
    const wrapper = mount(component, {
        props,
        global: {
            stubs: {
                AdminShell: {
                    props: ['title'],
                    template: '<main><h1>{{ title }}</h1><slot name="actions" /><slot /></main>',
                },
            },
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
const overview = {
    observed_at: '2026-11-16T09:00:00+00:00',
    accounts: 12,
    creator_workspaces: 7,
    brand_workspaces: 3,
    active_assignments: 4,
    expired_assignments: 0,
    pending_notices: 0,
    uncertain_sends: 0,
    failed_jobs: 0,
    scheduler_age_seconds: 20,
    scheduler_visible: true,
    operators_without_mfa: 0,
    long_suspensions: 0,
    support_awaiting: 0,
    support_expiring: 0,
    cleanup_blocked: 0,
    activity: [],
};
const entitlement = {
    state: 'active',
    assignment: {
        id: 'a',
        version: 2,
        starts_at: '2026-11-01T00:00:00+00:00',
        ends_at: '2026-12-01T00:00:00+00:00',
        created_at: '2026-11-01T00:00:00+00:00',
    },
    plan: { id: 'p', name: 'Example pilot plan', version: 1 },
    usage: [
        { resource: 'pipeline_entries', used: 12, limit: 10 },
        { resource: 'brand_contacts', used: 5, limit: 5 },
        { resource: 'reminders', used: 1, limit: 5 },
    ],
};

it('shows a calm overview, and never presents capability gaps or missing telemetry as zero or healthy', () => {
    let wrapper = render(PlatformOverview, { overview });
    expect(wrapper.text()).toContain('Nothing needs attention');
    expect(wrapper.text()).toContain('No administrative actions recorded yet');
    page.value.props.admin = { capabilities: ['directory.read'], environment: 'Development', verified: true };
    wrapper = render(PlatformOverview, {
        overview: {
            ...overview,
            failed_jobs: null,
            uncertain_sends: null,
            pending_notices: null,
            active_assignments: null,
            scheduler_age_seconds: null,
            scheduler_visible: false,
            activity: null,
        },
    });
    const failed = wrapper.findAll('.adm-stats li').find((card) => card.text().includes('Failed jobs'))!;
    expect(failed.text()).toContain('Unavailable');
    expect(failed.text()).toContain('Outside your capabilities');
    expect(failed.find('a').exists()).toBe(false);
    expect(wrapper.text()).toContain('Activity is outside your capabilities');
    expect(wrapper.text()).not.toContain('scheduler heartbeat');
});

it('lists attention items with a next step and a link to the filtered list', () => {
    const wrapper = render(PlatformOverview, {
        overview: {
            ...overview,
            uncertain_sends: 2,
            failed_jobs: 1,
            expired_assignments: 1,
            operators_without_mfa: 1,
            scheduler_age_seconds: null,
        },
    });
    const items = wrapper.findAll('.adm-attention li');
    expect(items).toHaveLength(5);
    // Failures come first; reviews keep their order after them.
    expect(items[0]!.text()).toContain('1 background job failed in the last 24 hours');
    expect(items[0]!.text()).toContain('Action needed');
    expect(items[1]!.text()).toContain('2 uncertain sends need review');
    expect(items[1]!.text()).toContain('Nothing is resent automatically');
    // The visible label is short; the accessible name keeps the full destination.
    const link = items[1]!.get('a');
    expect(link.attributes('href')).toBe('/admin/notifications?state=uncertain');
    expect(link.text()).toBe('Review');
    expect(link.attributes('aria-label')).toBe('Review uncertain sends');
    // Tiles link to their lists and flag what needs a look.
    const notices = wrapper.findAll('.adm-stats li').find((card) => card.text().includes('Queued notices'))!;
    expect(notices.text()).toContain('2 uncertain');
    expect(notices.get('a').attributes('href')).toBe('/admin/notifications?state=uncertain');
    expect(
        wrapper
            .findAll('.adm-stats li')
            .find((card) => card.text().includes('Failed jobs'))!
            .classes(),
    ).toContain('is-alert');
    expect(wrapper.text()).toContain('No scheduler heartbeat has been observed');
    expect(wrapper.text()).toContain('1 workspace has an expired assignment');
});

it('flags support access that is about to end and cleanup batches with blocked records, without offering to extend or delete', () => {
    const wrapper = render(PlatformOverview, { overview: { ...overview, support_expiring: 1, cleanup_blocked: 2 } });
    const items = wrapper.findAll('.adm-attention li');
    expect(items).toHaveLength(2);
    expect(items[0]!.text()).toContain('1 support grant ends within two hours');
    expect(items[0]!.text()).toContain('It cannot be extended');
    expect(items[0]!.get('a').attributes('href')).toBe('/admin/support-access?state=approved');
    expect(items[1]!.text()).toContain('2 cleanup batches have blocked records in the latest dry run');
    expect(items[1]!.text()).toContain('Dry runs delete nothing');
    expect(items[1]!.get('a').attributes('href')).toBe('/admin/cleanup');
    // Outside the operator's capabilities the figures are null and nothing is reported.
    const hidden = render(PlatformOverview, {
        overview: { ...overview, support_expiring: null, cleanup_blocked: null },
    });
    expect(hidden.text()).toContain('Nothing needs attention');
});

it('distinguishes an empty directory from no matches and restores applied filters', async () => {
    const props = { accounts: { total: 0, rows: [] }, filters: { q: '', verified: '', grant: '' }, page: 1 };
    const wrapper = render(PlatformAccounts, props);
    expect(wrapper.text()).toContain('No accounts are registered yet');
    await wrapper.setProps({ filters: { q: 'jordan', verified: 'no', grant: '' } });
    expect(wrapper.text()).toContain('No accounts match these filters');
    expect((wrapper.get('#account-search').element as HTMLInputElement).value).toBe('jordan');
    expect(wrapper.get('[aria-label="Applied filters"]').text()).toContain('Verification: Unverified');
    await wrapper.get('#account-search').setValue('unsent edit');
    expect(wrapper.get('[aria-label="Applied filters"]').text()).toContain('Search: jordan');
    await wrapper.get('form').trigger('submit');
    expect(get.mock.calls[0]![0]).toBe('/admin/accounts');
    expect(get.mock.calls[0]![1]).toEqual({ q: 'unsent edit', verified: 'no' });
});

it('filters both directories by a labelled, inclusive date range and reports it as applied', async () => {
    const accounts = render(PlatformAccounts, {
        accounts: { total: 0, rows: [] },
        filters: { q: '', verified: '', grant: '', access: '', from: '2026-11-01', to: '' },
        page: 1,
    });
    expect(accounts.get('label[for="account-joined-from"]').text()).toBe('Joined from');
    expect(accounts.get('label[for="account-joined-to"]').text()).toBe('Joined to (inclusive)');
    expect((accounts.get('#account-joined-from').element as HTMLInputElement).type).toBe('date');
    expect(accounts.get('[aria-label="Applied filters"]').text()).toContain('Joined from: 2026-11-01');
    await accounts.get('#account-joined-to').setValue('2026-11-15');
    expect(accounts.get('[aria-label="Applied filters"]').text()).not.toContain('Joined to');
    await accounts.get('form').trigger('submit');
    expect(get.mock.calls[0]![1]).toEqual({ from: '2026-11-01', to: '2026-11-15' });
    const workspaces = render(PlatformWorkspaces, {
        workspaces: { total: 0, rows: [] },
        filters: { q: '', kind: '', state: '', access: '', from: '', to: '2026-11-15' },
        page: 1,
    });
    expect(workspaces.get('label[for="workspace-created-to"]').text()).toBe('Created to (inclusive)');
    expect(workspaces.get('[aria-label="Applied filters"]').text()).toContain('Created to: 2026-11-15');
    expect(workspaces.text()).toContain('calendar days in UTC');
});

it('names recent actions and their targets in plain language, keeping unnamed targets as references', () => {
    const wrapper = render(PlatformOverview, {
        overview: {
            ...overview,
            activity: [
                {
                    id: '7',
                    occurred_at: '2026-11-16T08:00:00+00:00',
                    event: 'plan.assigned',
                    target: '919a4aad-2886-4aa4-a93a-333b953822c1',
                    actor: 'Ari Cruz',
                    target_kind: 'workspace',
                    target_name: 'Northline Living',
                },
                {
                    id: '6',
                    occurred_at: '2026-11-16T07:00:00+00:00',
                    event: 'cleanup.dry_run',
                    target: '3ab9340b-c84e-4c5d-86f4-5d1fae3fc457',
                    actor: 'Ari Cruz',
                    target_kind: 'cleanup',
                    target_name: null,
                },
                {
                    id: '5',
                    occurred_at: '2026-11-16T06:00:00+00:00',
                    event: 'something.new_kind',
                    target: 'admin',
                    actor: 'application',
                },
            ],
        },
    });
    const rows = wrapper.get('table').findAll('tbody tr');
    expect(rows[0]!.text()).toContain('Plan assigned');
    expect(rows[0]!.text()).toContain('Workspace · Northline Living');
    expect(rows[0]!.get('a').attributes('href')).toBe('/admin/audit/platform/7');
    expect(rows[0]!.get('time').attributes('datetime')).toBe('2026-11-16T08:00:00+00:00');
    expect(rows[1]!.text()).toContain('Cleanup dry run');
    expect(rows[1]!.text()).toContain('Cleanup · 3ab9340b…');
    // Unknown codes stay readable rather than hidden.
    expect(rows[2]!.text()).toContain('Something new kind');
    expect(rows[2]!.text()).toContain('admin');
});

it('offers account quick views with counts, one status per row and numbered pages', () => {
    const row = (id: number, extra: Record<string, unknown> = {}) => ({
        id,
        name: `Account ${id}`,
        email: `account-${id}@example.test`,
        email_verified_at: '2026-11-01T00:00:00+00:00',
        created_at: '2026-11-01T10:00:00+00:00',
        workspaces: 1,
        platform_access: false,
        suspended: false,
        ...extra,
    });
    const wrapper = render(PlatformAccounts, {
        accounts: {
            total: 60,
            facets: { all: 60, active: 58, suspended: 2, unverified: 3, granted: 1 },
            rows: [
                row(1),
                row(2, { suspended: true, email_verified_at: null }),
                row(3, { email_verified_at: null, platform_access: true }),
            ],
        },
        filters: { q: 'acc', verified: '', grant: '', access: 'suspended', from: '', to: '' },
        page: 2,
    });
    const chips = wrapper.get('[aria-label="Show accounts"]');
    expect(chips.text()).toContain('Suspended');
    expect(chips.get('[aria-current="true"]').text()).toContain('Suspended');
    // A quick view replaces the access, verification and grant filters and keeps the search.
    const unverified = chips.findAll('a').find((link) => link.text().includes('Unverified'))!;
    expect(unverified.attributes('href')).toBe('/admin/accounts?q=acc&verified=no');
    expect(unverified.text()).toContain('3');
    const statuses = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td')[0]!.text());
    // Suspension outranks verification.
    expect(statuses).toEqual(['Active', 'Suspended', 'Unverified']);
    expect(wrapper.findAll('tbody tr')[2]!.text()).toContain('Platform grant');
    expect(wrapper.findAll('tbody tr')[0]!.text()).toContain('None');
    expect(wrapper.text()).toContain('Showing 26–28 of 60 matching accounts, all pages');
    expect(wrapper.get('[aria-label="Page 3"]').attributes('href')).toBe(
        '/admin/accounts?q=acc&access=suspended&page=3',
    );
    expect(wrapper.get('[aria-current="page"]').text()).toBe('2');
});

it('applies account dropdown filters as they are chosen', async () => {
    const wrapper = render(PlatformAccounts, {
        accounts: { total: 0, rows: [], facets: { all: 0, active: 0, suspended: 0, unverified: 0, granted: 0 } },
        filters: { q: 'kim', verified: '', grant: '', access: '', from: '', to: '' },
        page: 1,
    });
    await wrapper.get('#account-verified-trigger, [aria-labelledby="account-verified-label"]').trigger('click');
    const option = wrapper.findAll('[role="option"]').find((item) => item.text() === 'Unverified')!;
    await option.trigger('click');
    expect(get).toHaveBeenCalledWith('/admin/accounts', { q: 'kim', verified: 'no' }, expect.anything());
});

const accountImpact = {
    suspended: false,
    version: 0,
    suspension: null,
    sessions: 2,
    memberships: 3,
    sole_owner_of: [{ id: 'w-1', name: 'Cedar Studio' }],
    platform_grant: false,
    self: false,
    last_owner: false,
};
function access(props: Record<string, unknown>) {
    // The spread carries per-test overrides, so the complete prop set is asserted here once.
    const merged = { kind: 'account', reference: '7', name: 'Jordan Vale', access: null, ...props };
    const wrapper = mount(AccessChange, {
        props: merged as InstanceType<typeof AccessChange>['$props'],
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
// Visible button labels; icon-only controls such as copy are excluded.
const buttons = (wrapper: VueWrapper) =>
    wrapper
        .findAll('button')
        .map((button) => button.text())
        .filter(Boolean);
const press = (wrapper: VueWrapper, label: string) =>
    wrapper
        .findAll('button')
        .find((button) => button.text() === label)!
        .trigger('click');

it('keeps access actions away from operators who cannot manage access and marks uncollected fields honestly', () => {
    const wrapper = render(PlatformAccount, {
        account: {
            id: 7,
            name: 'Jordan Vale',
            email: 'jordan@example.test',
            email_verified_at: null,
            created_at: '2026-10-01T00:00:00+00:00',
            updated_at: '2026-10-02T00:00:00+00:00',
            suspended: false,
        },
        security: { mfa_enrolled: false, mfa_enrolled_at: null, sessions: 2, grant: null },
        memberships: [],
        activity: null,
        tab: 'overview',
        access: null,
    });
    expect(wrapper.text()).toContain('Changing access is outside your capabilities');
    expect(wrapper.findAll('button').some((button) => /suspend|sign out/i.test(button.text()))).toBe(false);
    const sign = wrapper.findAll('dt').find((term) => term.text() === 'Last successful sign-in')!;
    expect((sign.element.nextElementSibling as HTMLElement).textContent).toContain('Not collected');
    expect(wrapper.text()).toContain('Not verified');
});

it('reviews a suspension with server-supplied impact, fresh proof and a destructive confirmation', async () => {
    const wrapper = access({ access: accountImpact });
    expect(wrapper.find('input').exists()).toBe(false);
    await press(wrapper, 'Suspend account');
    const text = wrapper.text();
    expect(text).toContain('Signs out 2 sessions and refuses sign-in on every device.');
    expect(text).toContain('Blocks access to 3 workspaces.');
    expect(text).toContain(
        'Leaves Cedar Studio with no owner who can sign in. That workspace is not suspended by this action.',
    );
    for (const id of ['#access-reason-account', '#access-password-account', '#access-code-account'])
        expect(wrapper.find(id).exists()).toBe(true);
    await wrapper.get('form').trigger('submit');
    expect(post).not.toHaveBeenCalled();
    expect(pending()).toMatchObject({ title: 'Suspend account', tone: 'danger' });
    expect(pending()?.description).toContain('Suspend Jordan Vale?');
    pending()?.action();
    expect(post.mock.calls[0]![0]).toBe('/admin/accounts/7/suspend');
    confirmation.value = null;
    await press(wrapper, 'Cancel');
    await press(wrapper, 'Sign out sessions');
    await wrapper.get('form').trigger('submit');
    expect(pending()).toMatchObject({ title: 'Sign out sessions', tone: 'danger' });
    expect(pending()?.description).toContain('They can sign in again straight away');
});

it('offers reactivation for a suspended subject and states what it does not restore', async () => {
    const wrapper = access({
        kind: 'workspace',
        reference: 'w-1',
        name: 'Cedar Studio',
        access: {
            suspended: true,
            version: 1,
            suspension: { reason: 'Abuse report', since: '2026-11-10T00:00:00+00:00', by: 'Morgan Reyes' },
            members: 2,
            partnerships: 1,
            pending_notifications: 0,
            ready_runs: 0,
            approved_drafts: 0,
            public_links: 1,
        },
    });
    expect(wrapper.text()).toContain('Suspended');
    expect(wrapper.text()).toContain('by Morgan Reyes. Reason: Abuse report');
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Reactivate workspace']);
    await press(wrapper, 'Reactivate workspace');
    expect(wrapper.text()).toContain('expired links and cancelled work are not restored or replayed');
    await wrapper.get('form').trigger('submit');
    expect(pending()).toMatchObject({ title: 'Reactivate workspace', tone: 'default' });
});

it('refuses to suspend the acting operator or the last usable platform owner', () => {
    expect(access({ access: { ...accountImpact, self: true } }).text()).toContain(
        'You cannot suspend your own account',
    );
    const last = access({ access: { ...accountImpact, last_owner: true, platform_grant: true } });
    expect(last.text()).toContain('This is the last usable platform owner');
    expect(last.findAll('button')).toHaveLength(0);
});

it('reports over-limit, reached and unconfigured usage without implying deletion or a zero limit', () => {
    const wrapper = mount(UsageTable, { props: { entitlement } });
    wrappers.push(wrapper);
    const rows = wrapper.findAll('tbody tr').map((row) => row.text());
    expect(rows[0]).toContain('Over limit by 2');
    expect(rows[0]).toContain('0');
    expect(rows[1]).toContain('Limit reached');
    expect(rows[2]).toContain('Within limit');
    const unassigned = mount(UsageTable, {
        props: {
            entitlement: {
                state: 'unassigned-development',
                assignment: null,
                plan: null,
                usage: [{ resource: 'reminders', used: 3, limit: null }],
            },
        },
    });
    wrappers.push(unassigned);
    expect(unassigned.text()).toContain('Not configured');
    expect(unassigned.text()).toContain('No plan configured');
});

it('reviews an assignment with its real consequences before any request is sent', async () => {
    const wrapper = render(PlatformAssign, { workspace: { id: 'w-1', name: 'Cedar Studio' }, entitlement });
    await wrapper.get('#assign-name').setValue('Example pilot plan');
    await wrapper.get('#assign-start').setValue('2099-01-01T09:00');
    await wrapper.get('#assign-end').setValue('2099-02-01T09:00');
    await wrapper.get('#quota-pipeline_entries').setValue('5');
    await wrapper.get('#assign-reason').setValue('Pilot renewal');
    expect(wrapper.get('.wd-warning').text()).toContain('One limit is below what this workspace already holds');
    await wrapper.get('form').trigger('submit');
    expect(post).not.toHaveBeenCalled();
    expect(pending()?.title).toBe('Record subscription assignment');
    expect(pending()?.description).toContain('Cedar Studio');
    expect(pending()?.description).toContain('It starts in the future: new records are blocked until then');
    expect(pending()?.description).toContain('existing records are kept');
    pending()?.action();
    expect(post).toHaveBeenCalledWith('/admin/assignments');
});

it('explains an uncertain send and only offers resolution to operators who may record one', () => {
    const notice = {
        id: 'n-1',
        org_id: 'w-1',
        workspace: 'Cedar Studio',
        kind: 'partner.invitation',
        state: 'uncertain',
        created_at: '2026-11-16T09:00:00+00:00',
        started_at: '2026-11-16T09:00:05+00:00',
        completed_at: null,
        available_at: '2026-11-16T09:00:00+00:00',
        defer_count: 0,
        failure_code: null,
        resolution: null,
        resolved_at: null,
        resolution_version: 0,
        actor: { id: 1, name: 'Jordan Vale' },
        resolver: null,
    };
    let wrapper = render(PlatformNotification, { notice, canResolve: false });
    expect(wrapper.text()).toContain('It is never retried automatically');
    expect(wrapper.text()).toContain('Recording a resolution is outside your capabilities');
    expect(wrapper.find('#delivery-reason').exists()).toBe(false);
    expect(wrapper.text()).toContain('Not available');
    expect(wrapper.findAll('button').some((button) => /resend|retry/i.test(button.text()))).toBe(false);
    wrapper = render(PlatformNotification, { notice, canResolve: true });
    expect(wrapper.find('#delivery-reason').exists()).toBe(true);
    wrapper = render(PlatformNotification, {
        notice: { ...notice, state: 'sent', completed_at: '2026-11-16T09:00:09+00:00' },
        canResolve: true,
    });
    expect(wrapper.text()).toContain('Recipient delivery is not proven');
    expect(wrapper.find('#delivery-reason').exists()).toBe(false);
});

it('labels unobserved services Unknown and shows only sanitized failure details', () => {
    const wrapper = render(PlatformOperations, {
        observed_at: '2026-11-16T09:00:00+00:00',
        scheduler: null,
        outbox: { pending: 0, oldest_pending_at: null, uncertain_unresolved: 0 },
        failed: {
            total: 1,
            last_24_hours: 1,
            rows: [
                {
                    reference: 'ref-1',
                    queue: 'default',
                    connection: 'redis',
                    failed_at: '2026-11-16T08:00:00+00:00',
                    job: 'App\\Jobs\\SendNotification',
                    category: null,
                },
            ],
        },
        queue: { name: 'default', backend: 'Redis', reachable: false, pending: null },
    });
    const services = wrapper
        .findAll('table')[0]!
        .findAll('tbody tr')
        .map((row) => row.text());
    expect(services.find((row) => row.startsWith('Scheduler'))).toContain('Unknown');
    expect(services.find((row) => row.startsWith('Queue store'))).toContain('Down');
    expect(services.find((row) => row.startsWith('Queue worker'))).toContain('Unknown');
    expect(wrapper.findAll('table')[1]!.text()).toContain('Unknown');
    expect(wrapper.findAll('table')[2]!.text()).toContain('SendNotification');
    expect(wrapper.findAll('table')[2]!.text()).toContain('Not classified');
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Refresh observations']);
});

it('protects the acting owner, and reviews reductions and revocations as destructive', async () => {
    const operators = [
        {
            user_id: 1,
            name: 'Morgan Reyes',
            email: 'morgan@example.test',
            verified: true,
            permissions: ['platform.owner'],
            version: 1,
            revoked_at: null,
            mfa_ready: true,
            self: true,
            last_owner: true,
        },
        {
            user_id: 2,
            name: 'Ari Cruz',
            email: 'ari@example.test',
            verified: true,
            permissions: ['audit.read', 'directory.read'],
            version: 3,
            revoked_at: null,
            mfa_ready: false,
            self: false,
            last_owner: false,
        },
    ];
    const capabilities = [
        'platform.owner',
        'directory.read',
        'plans.assign',
        'notifications.read',
        'notifications.reconcile',
        'operations.read',
        'audit.read',
    ];
    const wrapper = render(PlatformOperators, { operators, capabilities, candidate: null });
    const rows = wrapper.findAll('tbody tr');
    expect(rows[0]!.text()).toContain('You cannot change your own grant. You are the last usable platform owner.');
    expect(rows[0]!.findAll('button')).toHaveLength(0);
    expect(rows[1]!.text()).toContain('Directory, Audit review');
    expect(rows[1]!.text()).toContain('Not enrolled');
    expect(wrapper.find('#operator-reason').exists()).toBe(false);
    await rows[1]!
        .findAll('button')
        .find((button) => button.text() === 'Change')!
        .trigger('click');
    const boxes = wrapper.findAll<HTMLInputElement>('input[type="checkbox"]');
    expect(boxes.filter((box) => box.element.checked).map((box) => box.element.value)).toEqual([
        'directory.read',
        'audit.read',
    ]);
    await boxes.find((box) => box.element.value === 'audit.read')!.setValue(false);
    expect(wrapper.get('.wd-warning').text()).toContain('This removes: Audit review.');
    await wrapper.get('form').trigger('submit');
    expect(post).not.toHaveBeenCalled();
    expect(pending()).toMatchObject({ title: 'Reduce platform access', tone: 'danger' });
    expect(pending()?.description).toContain('Ari Cruz (ari@example.test)');
    confirmation.value = null;
    await rows[1]!
        .findAll('button')
        .find((button) => button.text() === 'Revoke')!
        .trigger('click');
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
    await wrapper.get('form').trigger('submit');
    expect(pending()).toMatchObject({ title: 'Revoke platform access', tone: 'danger' });
    expect(pending()?.description).toContain('Their workspace memberships are not affected');
});

it('refuses to start a grant for an unverified identity', () => {
    const wrapper = render(PlatformOperators, {
        operators: [],
        capabilities: ['directory.read'],
        candidate: { id: 9, name: 'Taylor Reed', email: 'taylor@example.test', verified: false },
    });
    expect(wrapper.text()).toContain('This account cannot receive platform access yet');
    expect(wrapper.find('#operator-reason').exists()).toBe(false);
});

const grant = {
    id: 'g-1',
    org_id: 'w-1',
    workspace: 'Cedar Studio',
    operator_id: 1,
    operator: 'Morgan Reyes',
    purpose: 'Check a stuck lead',
    resources: ['pipeline_entries', 'finance'],
    hours: 4,
    state: 'approved',
    requested_at: '2026-11-16T08:00:00+00:00',
    decided_at: '2026-11-16T08:05:00+00:00',
    decided_by: 'Jordan Vale',
    expires_at: '2026-11-16T12:05:00+00:00',
    ended_at: null,
    ended_by: null,
    end_reason: null,
    version: 2,
    mine: true,
};

it('shows a persistent support banner, only approved areas, and no way to edit or export', async () => {
    const wrapper = render(PlatformSupportGrant, {
        grant,
        area: 'pipeline_entries',
        records: {
            rows: [
                {
                    id: 'r-1',
                    brand_name: 'Loom & Co',
                    stage: 'lead',
                    value_minor: 125000,
                    currency: 'USD',
                    created_at: '2026-11-01T00:00:00+00:00',
                },
            ],
            total: 1,
        },
        page: 1,
    });
    expect(wrapper.get('.adm-support-banner').text()).toContain(
        'you are viewing Cedar Studio as Morgan Reyes, read-only',
    );
    expect(wrapper.get('.adm-support-banner').text()).toContain('You are not signed in as a member');
    expect(wrapper.findAll('nav[aria-label="Record areas in this grant"] a').map((link) => link.text())).toEqual([
        'Leads',
        'Invoice drafts',
        'Payment records',
    ]);
    expect(wrapper.text()).toContain('1250.00 USD');
    expect(buttons(wrapper)).toEqual(['End support access']);
    await press(wrapper, 'End support access');
    await wrapper.get('form').trigger('submit');
    expect(pending()).toMatchObject({ title: 'End support access', tone: 'danger' });
});

it('shows nothing viewable before approval or to another operator', () => {
    const waiting = render(PlatformSupportGrant, {
        grant: { ...grant, state: 'requested', decided_at: null, decided_by: null, expires_at: null },
        area: null,
        records: null,
        page: 1,
    });
    expect(waiting.find('.adm-support-banner').exists()).toBe(false);
    expect(waiting.text()).toContain('Only an owner of this workspace can approve');
    expect(waiting.text()).not.toContain('Records in scope');
    expect(buttons(waiting)).toEqual(['Withdraw request']);
    const other = render(PlatformSupportGrant, {
        grant: { ...grant, mine: false },
        area: null,
        records: null,
        page: 1,
    });
    expect(other.find('.adm-support-banner').exists()).toBe(false);
    expect(other.text()).toContain('Only Morgan Reyes, who holds this grant, can view the records it covers.');
    const expired = render(PlatformSupportGrant, {
        grant: { ...grant, state: 'expired' },
        area: null,
        records: null,
        page: 1,
    });
    expect(expired.text()).not.toContain('Records in scope');
    expect(buttons(expired)).toHaveLength(0);
});

it('lets a workspace owner approve with a plain-language review, decline at once, and end access', async () => {
    page.value.props.account = { name: 'Jordan Vale', email: 'jordan@example.test' };
    const request = {
        id: 'g-1',
        operator: 'Morgan Reyes',
        purpose: 'Check a stuck lead',
        resources: ['pipeline_entries'],
        hours: 1,
        state: 'requested',
        requested_at: '2026-11-16T08:00:00+00:00',
        decided_at: null,
        decided_by: null,
        expires_at: null,
        ended_at: null,
        end_reason: null,
        version: 1,
    };
    const wrapper = mount(WorkspaceSupport, {
        props: {
            requests: [
                request,
                { ...request, id: 'g-2', state: 'approved', version: 2, expires_at: '2026-11-16T09:00:00+00:00' },
            ],
            workspace: 'Cedar Studio',
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
    });
    wrappers.push(wrapper);
    expect(wrapper.text()).toContain('Needs your decision');
    await press(wrapper, 'Approve');
    expect(routerPost).not.toHaveBeenCalled();
    expect(pending()?.title).toBe('Approve support access');
    expect(pending()?.description).toContain('view Leads in Cedar Studio for 1 hour');
    expect(pending()?.description).toContain('view but not change, download or export');
    pending()?.action();
    expect(routerPost.mock.calls[0]![0]).toBe('/workspace/support-access/g-1');
    expect(routerPost.mock.calls[0]![1]).toEqual({ expected_version: 1, decision: 'approve' });
    confirmation.value = null;
    await press(wrapper, 'Decline');
    expect(routerPost.mock.calls[1]![1]).toEqual({ expected_version: 1, decision: 'decline' });
    await press(wrapper, 'End access now');
    expect(pending()).toMatchObject({ title: 'End support access', tone: 'danger' });
});

it('presents a cleanup dry run as a preview with reasons and no way to execute it', () => {
    const wrapper = render(PlatformCleanupRun, {
        run: {
            id: 'run-1',
            batch_id: 'b-1',
            batch: 'Browser run 12',
            created_at: '2026-11-16T09:00:00+00:00',
            created_by: 'Morgan Reyes',
            eligible: 1,
            blocked: 1,
            protected: 1,
            stale: true,
            items: [
                {
                    type: 'workspace',
                    reference: 'w-1',
                    name: 'Cedar Studio',
                    evidence: 'Seeded by run 12',
                    disposition: 'blocked',
                    reasons: ['Holds finance records, which cleanup never removes'],
                    records: { invoice_drafts: 2, pipeline_entries: 4 },
                },
                {
                    type: 'account',
                    reference: '1',
                    name: 'Morgan Reyes',
                    evidence: 'Seeded by run 12',
                    disposition: 'protected',
                    reasons: ['Holds a platform grant; operators are always protected'],
                    records: { memberships: 0, sessions: 1 },
                },
                {
                    type: 'account',
                    reference: '9',
                    name: 'Synthetic User',
                    evidence: 'Seeded by run 12',
                    disposition: 'eligible',
                    reasons: [],
                    records: { memberships: 0, sessions: 0 },
                },
            ],
        },
    });
    expect(wrapper.text()).toContain('Preview — no data deleted');
    expect(wrapper.text()).toContain('This dry run is out of date');
    const rows = wrapper.findAll('tbody tr').map((row) => row.text());
    expect(rows[0]).toContain('Blocked');
    expect(rows[0]).toContain('Holds finance records, which cleanup never removes');
    expect(rows[0]).toContain('Invoice drafts 2');
    expect(rows[1]).toContain('Protected');
    expect(rows[2]).toContain('Eligible');
    expect(wrapper.text()).toContain('This cleanup cannot be executed from the console');
    expect(
        wrapper.findAll('button').filter((button) => !button.attributes('aria-label')?.startsWith('Copy')),
    ).toHaveLength(0);
    expect(wrapper.findAll('form')).toHaveLength(0);
});

it('draws the enrollment QR code in the browser from the setup key', async () => {
    const { default: PlatformSecurity } = await import('../../resources/js/pages/PlatformSecurity.vue');
    const { enrollmentUri } = await import('../../resources/js/features/auth/enrollment');
    const request = vi.spyOn(globalThis, 'fetch');
    page.value.props.account = { name: 'Example Owner', email: 'owner+admin@example.test' };
    const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    expect(enrollmentUri(secret, 'owner+admin@example.test', 'WeavDash Admin')).toBe(
        `otpauth://totp/WeavDash%20Admin:owner%2Badmin%40example.test?secret=${secret}&issuer=WeavDash%20Admin&algorithm=SHA1&digits=6&period=30`,
    );

    const pending = render(PlatformSecurity, { enrolled: false, secret });
    const code = pending.get('svg[role="img"]');
    expect(code.attributes('aria-label')).toBe('QR code for authenticator setup');
    expect(code.get('path').attributes('d')).toMatch(/^(M\d+ \d+h1v1h-1z)+$/);
    // The secret appears only as the copyable setup key: never in an image address or a request.
    expect(pending.find('img').exists()).toBe(false);
    expect(code.html()).not.toContain(secret);
    expect(pending.get('code').text()).toBe(secret);
    expect(request).not.toHaveBeenCalled();

    expect(render(PlatformSecurity, { enrolled: false }).find('svg[role="img"]').exists()).toBe(false);
    expect(render(PlatformSecurity, { enrolled: true }).find('svg[role="img"]').exists()).toBe(false);
});
