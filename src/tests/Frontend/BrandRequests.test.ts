import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive, type Component } from 'vue';
import BrandRequest from '../../resources/js/pages/BrandRequest.vue';
import BrandRequests from '../../resources/js/pages/BrandRequests.vue';
import PlatformBrandRequest from '../../resources/js/pages/PlatformBrandRequest.vue';
import PlatformBrandRequests from '../../resources/js/pages/PlatformBrandRequests.vue';
import PlatformOverview from '../../resources/js/pages/PlatformOverview.vue';
import PlatformWorkspace from '../../resources/js/pages/PlatformWorkspace.vue';
import WorkspaceScreen from '../../resources/js/features/workspaces/WorkspaceScreen.vue';
import { visibleGroups } from '../../resources/js/features/admin/navigation';
import { confirmation, type Confirmation } from '../../resources/js/composables/useConfirmation';

const pending = (): Confirmation | null => confirmation.value;
const { post, patch, routerPost, visit, reload, page } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    routerPost: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
    page: { value: undefined as unknown as { url: string; props: Record<string, unknown> } },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: make } = await import('vue');
    return {
        usePage: () => page.value,
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<span />' },
        router: { get: vi.fn(), post: routerPost, visit, reload },
        useForm: (data: Record<string, unknown>) => {
            const form = make({ ...data, errors: {} as Record<string, string>, processing: false, post, patch });
            return form;
        },
    };
});
const wrappers: VueWrapper[] = [];
beforeEach(() => {
    page.value = reactive({
        url: '/brand/requests',
        props: {
            account: { name: 'Ana Reyes', email: 'ana@example.test' },
            admin: { capabilities: ['brands.review'], environment: 'Development', verified: true },
        },
    });
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmation.value = null;
    vi.clearAllMocks();
});
const shells = {
    FoundationShell: { template: '<main><slot /></main>' },
    AdminShell: {
        props: ['title', 'description'],
        template: '<main><h1>{{ title }}</h1><p>{{ description }}</p><slot name="actions" /><slot /></main>',
    },
};
function render(component: Component, props: Record<string, unknown>) {
    const wrapper = mount(component, { props, global: { stubs: shells }, attachTo: document.body });
    wrappers.push(wrapper);
    return wrapper;
}
const fields = {
    brand_name: 'Northwind',
    website: 'https://northwind.example.test',
    applicant_role: 'Marketing lead',
    intended_use: 'Run our partner programme',
};
const request = {
    id: 'r-1',
    ...fields,
    status: 'pending',
    version: 1,
    submitted_at: '2026-11-18T08:00:00+00:00',
    updated_at: '2026-11-18T08:00:00+00:00',
    decided_at: null,
    workspace: null,
};
const submitted = { version: 1, event: 'submitted', occurred_at: '2026-11-18T08:00:00+00:00', submission: fields };

it('replaces Brand creation in the workspace selector with a request link', async () => {
    const wrapper = mount(WorkspaceScreen, {
        props: { account: { name: 'Ana', email: 'ana@example.test' }, workspaces: [], activeOrgId: null },
        global: { stubs: shells },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    expect(wrapper.find('#workspace_name').exists()).toBe(true);
    await wrapper.get('input[value="brand"]').setValue(true);
    expect(wrapper.find('#workspace_name').exists()).toBe(false);
    const create = wrapper.get('[aria-labelledby="workspace-create-title"]');
    expect(create.find('button[type="submit"]').exists()).toBe(false);
    const link = wrapper.get('a[href="/brand/requests"]');
    expect(link.text()).toBe('Request Brand workspace');
    expect(wrapper.text()).toContain('reviewed before they are created');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.emitted('create')).toBeUndefined();
    // Partner and Professional creation stay available.
    await wrapper.get('input[value="professional"]').setValue(true);
    await wrapper.get('#workspace_name').setValue('Solo');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.emitted('create')).toEqual([['Solo', 'creator', 'professional']]);
});

it('collects the four required fields, states the email consent and submits without a confirmation', async () => {
    const wrapper = render(BrandRequests, { requests: [], open: false, registering: true });
    expect(wrapper.text()).toContain('One step remains');
    expect(wrapper.text()).toContain('you agree to receive email updates about this request at ana@example.test');
    expect(wrapper.text()).toContain('No Brand workspace requests yet');
    for (const id of ['brand-name', 'brand-website', 'brand-role', 'brand-use']) {
        const control = wrapper.get(`#${id}`);
        expect(control.attributes('required')).toBeDefined();
        expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
    }
    expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    await wrapper.get('#brand-name').setValue('Northwind');
    await wrapper.get('form').trigger('submit');
    expect(pending()).toBeNull();
    expect(post).toHaveBeenCalledWith('/brand/requests', { preserveScroll: true });
});

it('shows the open request and its status instead of a second form', () => {
    const wrapper = render(BrandRequests, {
        requests: [request, { ...request, id: 'r-0', status: 'declined', decided_at: '2026-11-10T08:00:00+00:00' }],
        open: true,
        registering: false,
    });
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).toContain('In review');
    expect(wrapper.text()).toContain('one request open at a time');
    expect(wrapper.text()).toContain('Not approved');
    expect(wrapper.findAll('a[href="/brand/requests/r-1"]').length).toBeGreaterThan(0);
});

it('keeps a request in review read-only and makes one needing information editable with its history', async () => {
    const readOnly = render(BrandRequest, { request: { ...request, history: [submitted] } });
    expect(readOnly.find('form').exists()).toBe(false);
    expect(readOnly.text()).toContain('You cannot change it while it is in review');
    expect(readOnly.text()).toContain('Run our partner programme');

    const asked = {
        version: 2,
        event: 'information_requested',
        occurred_at: '2026-11-18T09:00:00+00:00',
        feedback: 'Add your registration page.',
        submission: null,
    };
    const wrapper = render(BrandRequest, {
        request: { ...request, status: 'needs_information', version: 2, history: [asked, submitted] },
    });
    expect(wrapper.text()).toContain('Feedback from the reviewer');
    expect(wrapper.text()).toContain('Add your registration page.');
    expect((wrapper.get('#revise-website').element as HTMLInputElement).value).toBe(fields.website);
    await wrapper.get('#revise-website').setValue('https://northwind.example.test/registration');
    await wrapper.get('form').trigger('submit');
    expect(patch).toHaveBeenCalledWith('/brand/requests/r-1', { preserveScroll: true });
    // The earlier submission stays visible in the history.
    expect(wrapper.text()).toContain('Request submitted');
    expect(wrapper.text()).toContain('More information requested');
});

it('offers Open Brand workspace after approval without switching workspaces by itself', async () => {
    const wrapper = render(BrandRequest, {
        request: {
            ...request,
            status: 'approved',
            version: 2,
            decided_at: '2026-11-18T10:00:00+00:00',
            workspace: { id: 'org-1', name: 'Northwind', member: true },
            history: [submitted],
        },
    });
    expect(routerPost).not.toHaveBeenCalled();
    const open = wrapper.findAll('button').find((button) => button.text() === 'Open Brand workspace')!;
    await open.trigger('click');
    expect(routerPost.mock.calls[0]![0]).toBe('/workspace/select');
    expect(routerPost.mock.calls[0]![1]).toEqual({ org_id: 'org-1' });

    const declined = render(BrandRequest, {
        request: {
            ...request,
            status: 'declined',
            decided_at: '2026-11-18T10:00:00+00:00',
            history: [
                {
                    version: 2,
                    event: 'declined',
                    occurred_at: '2026-11-18T10:00:00+00:00',
                    feedback: 'Not a business.',
                },
            ],
        },
    });
    expect(declined.text()).toContain('Not a business.');
    expect(declined.get('a[href="/brand/requests"].wd-button').text()).toBe('Submit a new request');
});

const reviewed = {
    request: { ...request, source: 'request' },
    applicant: { id: 7, name: 'Ana Reyes', email: 'ana@example.test', verified: true, suspended: false, self: false },
    workspace: null,
    history: [{ ...submitted, actor: 'Ana Reyes', operator: null, reason: null }],
    canDirectory: true,
};

it('lists requests with status, applicant and source for reviewers', () => {
    const wrapper = render(PlatformBrandRequests, {
        requests: {
            total: 2,
            pending: 1,
            facets: { all: 6, pending: 1, needs_information: 2, approved: 2, declined: 1 },
            rows: [
                { ...request, source: 'request', applicant: 'Ana Reyes', applicant_email: 'ana@example.test' },
                {
                    ...request,
                    id: 'r-2',
                    brand_name: 'Legacy Brand',
                    source: 'rollout',
                    status: 'approved',
                    applicant: null,
                    applicant_email: null,
                },
            ],
        },
        filters: { q: '', status: '', source: '' },
        page: 1,
    });
    expect(wrapper.text()).toContain('1 request is awaiting a decision.');
    expect(wrapper.get('a[href="/admin/brand-requests/r-1"]').text()).toBe('Northwind');
    expect(wrapper.text()).toContain('Awaiting decision');
    expect(wrapper.text()).toContain('Rollout approval');
    expect(wrapper.text()).toContain('No applicant: existing workspace');
    // Status chips with counts keep the search and source.
    const chips = wrapper.get('[aria-label="Request status"]');
    expect(chips.get('[aria-current="true"]').text()).toContain('All');
    expect(
        chips
            .findAll('a')
            .find((link) => link.text().includes('Waiting for applicant'))!
            .text(),
    ).toContain('2');
    expect(
        chips
            .findAll('a')
            .find((link) => link.text().includes('Declined'))!
            .attributes('href'),
    ).toBe('/admin/brand-requests?status=declined');
});

it('requires feedback, labels it as visible to the applicant and confirms approval and decline with Cancel available', async () => {
    const wrapper = render(PlatformBrandRequest, reviewed);
    expect(wrapper.text()).toContain('Feedback for the applicant');
    expect(wrapper.text()).toContain('The applicant reads this on their request page');
    expect(wrapper.get('#brand-feedback').attributes('required')).toBeDefined();
    const link = wrapper.get(`a[href="${fields.website}"]`);
    expect(link.attributes('rel')).toContain('noopener');
    await wrapper.get('#brand-feedback').setValue('Looks like a real business.');
    // Test-utils exposes the page's form so the test can set the decision and a server error directly.
    const state = (
        wrapper.vm as unknown as {
            form: { decision: string; feedback: string; errors: Record<string, string>; expected_version: number };
        }
    ).form;
    state.decision = 'request_information';
    await wrapper.get('form').trigger('submit');
    // Asking for information is routine: it is sent without a confirmation dialog.
    expect(pending()).toBeNull();
    expect(post).toHaveBeenCalledWith('/admin/brand-requests/r-1/decision', { preserveScroll: true });
    post.mockClear();

    state.decision = 'approve';
    await wrapper.get('form').trigger('submit');
    expect(post).not.toHaveBeenCalled();
    expect(pending()).toMatchObject({ title: 'Approve request', tone: 'default' });
    expect(pending()?.description).toContain('Ana Reyes (ana@example.test)');
    expect(pending()?.description).toContain('A Brand workspace named Northwind is created now');
    expect(pending()?.description).toContain('You do not become a member');
    pending()?.action();
    expect(post).toHaveBeenCalledWith('/admin/brand-requests/r-1/decision', { preserveScroll: true });
    confirmation.value = null;

    state.decision = 'decline';
    await wrapper.get('form').trigger('submit');
    expect(pending()).toMatchObject({ title: 'Decline request', tone: 'danger' });
    expect(pending()?.description).toContain('closed permanently and no workspace is created');
    confirmation.value = null;

    // A stale-version error keeps the feedback and offers the latest version without leaving the page.
    state.errors = { expected_version: 'This request changed or was already decided. Reload it before deciding.' };
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[role="alert"]').text()).toContain('This request changed');
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Load the latest version')!
        .trigger('click');
    expect(reload).toHaveBeenCalled();
    await wrapper.setProps({ request: { ...reviewed.request, version: 3 } });
    expect(state.expected_version).toBe(3);
    expect((wrapper.get('#brand-feedback').element as HTMLTextAreaElement).value).toBe('Looks like a real business.');
});

it('withholds approval for unverified or suspended applicants, own requests and requests not in review', () => {
    const unverified = render(PlatformBrandRequest, {
        ...reviewed,
        applicant: { ...reviewed.applicant, verified: false },
    });
    expect(unverified.text()).toContain("Approval is unavailable: the applicant's email is not verified.");
    expect(unverified.text()).not.toContain('Approve: create the Brand workspace');
    const suspended = render(PlatformBrandRequest, {
        ...reviewed,
        applicant: { ...reviewed.applicant, suspended: true },
    });
    expect(suspended.text()).toContain("the applicant's account is suspended");
    const own = render(PlatformBrandRequest, { ...reviewed, applicant: { ...reviewed.applicant, self: true } });
    expect(own.text()).toContain('You cannot review your own request');
    expect(own.find('form').exists()).toBe(false);
    const waiting = render(PlatformBrandRequest, {
        ...reviewed,
        request: { ...reviewed.request, status: 'needs_information' },
    });
    expect(waiting.find('form').exists()).toBe(false);
    expect(waiting.text()).toContain('Waiting for the applicant to update and resubmit');
    const rollout = render(PlatformBrandRequest, {
        request: { ...request, ...{ brand_name: null, website: null }, source: 'rollout', status: 'approved' },
        applicant: null,
        workspace: { id: 'org-9', name: 'Legacy Brand', created_at: '2026-10-22T08:00:00+00:00', suspended: false },
        history: [
            {
                version: 1,
                event: 'rollout_approved',
                occurred_at: '2026-11-18T08:00:00+00:00',
                actor: 'Founder',
                operator: 'ops',
                reason: 'Existing Brand workspaces approved at rollout',
                submission: null,
            },
        ],
        canDirectory: true,
    });
    expect(rollout.get('h1').text()).toBe('Legacy Brand');
    expect(rollout.find('form').exists()).toBe(false);
    expect(rollout.text()).toContain('Approved in the rollout');
    expect(rollout.text()).toContain('recorded by ops');
    expect(rollout.text()).not.toContain('Business information');
});

it('adds Brand requests to navigation and the overview only for the review capability', () => {
    const links = (capabilities: string[]) =>
        visibleGroups({ capabilities, environment: 'Development', verified: true }).flatMap((group) =>
            group.links.map((link) => link.href),
        );
    expect(links(['brands.review'])).toContain('/admin/brand-requests');
    expect(links(['platform.owner'])).toContain('/admin/brand-requests');
    expect(links(['directory.read'])).not.toContain('/admin/brand-requests');
    const overview = {
        observed_at: '2026-11-18T08:00:00+00:00',
        accounts: null,
        creator_workspaces: null,
        brand_workspaces: null,
        active_assignments: null,
        expired_assignments: null,
        pending_notices: null,
        uncertain_sends: null,
        failed_jobs: null,
        scheduler_age_seconds: null,
        scheduler_visible: false,
        operators_without_mfa: null,
        long_suspensions: null,
        support_awaiting: null,
        support_expiring: null,
        cleanup_blocked: null,
        brand_requests_pending: 2,
        brands_unrecorded: 1,
        activity: null,
    };
    const wrapper = render(PlatformOverview, { overview });
    expect(wrapper.text()).toContain('2 Brand requests are awaiting a decision');
    expect(wrapper.get('a[href="/admin/brand-requests?status=pending"]').attributes('aria-label')).toBe(
        'Review Brand requests',
    );
    expect(wrapper.text()).toContain('1 Brand workspace has no recorded approval');
});

it('shows approval status, reviewer, time, reason and source on a Brand workspace', () => {
    const workspace = {
        workspace: {
            id: 'org-9',
            name: 'Legacy Brand',
            kind: 'brand',
            experience: 'partner',
            created_at: '2026-10-22T08:00:00+00:00',
            updated_at: '2026-10-22T08:00:00+00:00',
            suspended: false,
        },
        access: null,
        members: [],
        entitlement: { state: 'unassigned-development', assignment: null, plan: null, usage: [] },
        operations: {
            partner_invitations: {},
            partnerships: {},
            onboarding_requests: {},
            onboarding_documents: {},
            notifications: {},
            observed_at: '2026-11-18T08:00:00+00:00',
        },
        activity: [],
        tab: 'overview',
        canReviewBrands: true,
    };
    const wrapper = render(PlatformWorkspace, {
        ...workspace,
        approval: {
            status: 'approved',
            source: 'rollout',
            request: 'r-9',
            approved_at: '2026-11-18T08:00:00+00:00',
            reviewer: 'Founder',
            operator: 'ops',
            reason: 'Existing Brand workspaces approved at rollout',
        },
    });
    const text = wrapper.text();
    for (const part of ['Brand approval', 'Rollout approval', 'Founder', 'ops', 'Existing Brand workspaces approved']) {
        expect(text).toContain(part);
    }
    expect(text).toContain('not from a signup review');
    expect(wrapper.get('a[href="/admin/brand-requests/r-9"]').text()).toBe('View approval record');
    expect(render(PlatformWorkspace, { ...workspace, approval: { status: 'unrecorded' } }).text()).toContain(
        'No approval recorded',
    );
    expect(render(PlatformWorkspace, { ...workspace, approval: null }).text()).not.toContain('Brand approval');
});
