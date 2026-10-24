import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import OnboardingForm from '../../resources/js/pages/OnboardingForm.vue';
import PublicPresence from '../../resources/js/pages/PublicPresence.vue';
import Pipeline from '../../resources/js/features/pipeline/LeadManager.vue';
const api = vi.hoisted(() => ({ visit: vi.fn(), put: vi.fn(), post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: api,
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
}));
HTMLDialogElement.prototype.showModal = function () {
    this.open = true;
};
HTMLDialogElement.prototype.close = function () {
    this.open = false;
};
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((w) => w.unmount());
    wrappers.length = 0;
    vi.clearAllMocks();
});
function success(call: unknown[]): void {
    (call[call.length - 1] as { onSuccess: () => void }).onSuccess();
}

it('keeps an onboarding draft tied to its own save when a newer edit appears during redirect', async () => {
    const form = {
        id: 'request',
        label: 'Form',
        status: 'draft',
        business_name: 'Initial',
        contact_name: '',
        business_email: '',
        website: '',
        brief: '',
        correction_note: '',
        version: 1,
        documents: [],
    };
    const wrapper = mount(OnboardingForm, { props: { form } });
    wrappers.push(wrapper);
    await wrapper.get('#onboard-business').setValue('My saved draft');
    await wrapper.findAll('form')[0]!.trigger('submit');
    await wrapper.setProps({ form: { ...form, business_name: 'Another writer', version: 3 } });
    success(api.visit.mock.calls[0]!);
    await wrapper.findAll('form')[0]!.trigger('submit');
    expect(api.visit.mock.calls[1]?.[1].data).toMatchObject({ business_name: 'My saved draft', version: 2 });
});

it('does not acknowledge another media-kit edit that arrived after the submitted version', async () => {
    const kit = { id: 'kit', title: 'Initial', bio: '', website: '', published: false, version: 1, views: 0 };
    const wrapper = mount(PublicPresence, {
        props: {
            account: { name: 'Creator', email: 'creator@example.test' },
            workspace: { id: 'org', name: 'Workspace' },
            canWrite: true,
            kit,
            links: [],
            issuedLink: null,
        },
    });
    wrappers.push(wrapper);
    await wrapper.get('#kit-title').setValue('My title');
    await wrapper.get('form').trigger('submit');
    await wrapper.setProps({ kit: { ...kit, title: 'Another writer', version: 3 } });
    success(api.put.mock.calls[0]!);
    await wrapper.get('form').trigger('submit');
    expect(api.put.mock.calls[1]?.[1]).toMatchObject({ title: 'My title', version: 2 });
});

it('preserves the pipeline draft version across an activity redirect containing a later edit', async () => {
    const selected = {
        id: 'lead',
        brand_name: 'Brand',
        contact_name: null,
        contact_email: null,
        stage: 'lead',
        value_minor: '0',
        currency: 'PHP',
        next_action_due: null,
        version: 1,
        notes: 'Initial',
        activities: [],
    };
    const wrapper = mount(Pipeline, {
        props: {
            workspace: { id: 'org', name: 'Workspace' },
            entries: [],
            stages: [],
            counts: [],
            totals: [],
            nextCursor: null,
            selected,
            canWrite: true,
            filters: { q: '', stage: '', due: false },
        },
    });
    wrappers.push(wrapper);
    await wrapper.get('#edit-notes').setValue('Unsaved private draft');
    await wrapper.get('#activity-body').setValue('Logged activity');
    await wrapper
        .findAll('form')
        .find((form) => form.find('#activity-body').exists())!
        .trigger('submit');
    await wrapper.setProps({ selected: { ...selected, notes: 'Another writer', version: 3 } });
    success(api.visit.mock.calls[0]!);
    await wrapper
        .findAll('form')
        .find((form) => form.find('#edit-brand').exists())!
        .trigger('submit');
    expect(api.visit.mock.calls[1]?.[1].data).toMatchObject({ notes: 'Unsaved private draft', version: 2 });
});
