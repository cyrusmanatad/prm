import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import LeadManager from '../../resources/js/features/pipeline/LeadManager.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import type { EntryDetail } from '../../resources/js/features/pipeline/types';

const { post, get, visit } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: { post, get, visit, on: () => () => {} },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('../../resources/js/composables/useNotify', () => ({
    useNotify: () => ({ error: vi.fn(), dismiss: vi.fn() }),
}));
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event('close'));
};
const stages = [
    { value: 'lead', label: 'Lead' },
    { value: 'pitched', label: 'Pitched' },
];
const base: EntryDetail = {
    id: 'one',
    brand_name: 'Kanto Cafe linens',
    contact_name: 'Ana Reyes',
    contact_email: 'ana@example.test',
    stage: 'lead',
    currency: 'PHP',
    value_minor: '4000000',
    version: 3,
    next_action_due: null,
    notes: 'Six branches.',
    activities: [
        { id: 'a2', kind: 'call', body: 'Called Ana.', occurred_at: '2026-11-21T03:00:00Z' },
        { id: 'a1', kind: 'created', body: '', occurred_at: '2026-11-20T03:00:00Z' },
    ],
    updated_at: new Date(Date.now() - 5 * 60_000).toISOString(),
    onboarding: null,
    sharing: [],
};
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmation.value = null;
    vi.clearAllMocks();
});
async function drawer(selected: Partial<EntryDetail> = {}, canWrite = true) {
    const wrapper = mount(LeadManager, {
        props: {
            entries: [base],
            counts: [{ stage: 'lead', count: 1 }],
            totals: [],
            filters: { q: '', stage: '', due: false },
            stages,
            selected: { ...base, ...selected },
            nextCursor: null,
            canWrite,
            workspace: { id: 'workspace', name: 'Workspace' },
        },
        global: { provide: { workspaceExperience: ref('partner') } },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    await nextTick();
    await nextTick();
    return wrapper;
}

it('shows a compact header with the value, last update, activity count and a named delete action', async () => {
    const wrapper = await drawer();
    const header = wrapper.get('.drawer-header');
    expect(header.get('h2').text()).toBe('Kanto Cafe linens');
    expect(header.text()).toContain('PHP 40,000.00');
    expect(header.text()).toContain('Updated');
    expect(header.get('.contacts-pill').attributes('href')).toBe('/partner/contacts?entry=one');
    expect(wrapper.get('#lead-tab-activity').text()).toContain('2');
    // The contact fields match Add lead.
    expect(wrapper.find('label[for="edit-contact"]').text()).toBe('Contact name');
    expect(wrapper.find('label[for="edit-email"]').text()).toBe('Contact email');
    const remove = wrapper.get('button[aria-label="Delete lead"]');
    await remove.trigger('click');
    expect(confirmation.value?.title).toBe('Delete permanently');
    expect(confirmation.value?.description).toContain('Kanto Cafe linens');
    confirmation.value?.action();
    expect(visit.mock.calls[0][0]).toBe('/pipeline/one');
    expect(visit.mock.calls[0][1]).toMatchObject({ method: 'delete', data: { version: 3 } });
});

it('enables Save only for changes, says so in the footer and discards them on request', async () => {
    const wrapper = await drawer();
    const save = () => wrapper.get('.drawer-footer button[type="submit"]');
    expect(save().attributes()).toHaveProperty('disabled');
    expect(wrapper.get('.drawer-footer').text()).toContain('No unsaved changes');
    await wrapper.get('#edit-contact').setValue('Ana B. Reyes');
    expect(wrapper.get('.drawer-dirty').text()).toBe('Unsaved changes');
    expect(save().attributes()).not.toHaveProperty('disabled');
    const discard = wrapper.findAll('.drawer-footer button').find((button) => button.text() === 'Discard')!;
    await discard.trigger('click');
    expect((wrapper.get('#edit-contact').element as HTMLInputElement).value).toBe('Ana Reyes');
    expect(wrapper.find('.drawer-dirty').exists()).toBe(false);
    expect(save().attributes()).toHaveProperty('disabled');
});

it('reports onboarding and Brand sharing, and keeps leads with onboarding history', async () => {
    const fresh = await drawer();
    const rows = () => fresh.findAll('.drawer-status-row');
    expect(rows()[0]!.text()).toContain('Not started');
    expect(rows()[0]!.text()).toContain('Start onboarding…');
    expect(rows()[1]!.text()).toContain('Not shared with any Brand');
    expect(rows()[1]!.get('a').text()).toBe('Share with a Brand');
    expect(rows()[1]!.get('a').attributes('href')).toBe('/partner/brand-sharing?lead=one');

    const started = await drawer({
        onboarding: { id: 'request-1', status: 'submitted', created_at: '2026-11-20T03:00:00Z' },
        sharing: [{ brand_name: 'Northline Apparel', paused: false, registered_at: '2026-11-19T03:00:00Z' }],
    });
    const [onboarding, sharing] = started.findAll('.drawer-status-row');
    expect(onboarding!.text()).toContain('Awaiting review');
    expect(onboarding!.get('a').attributes('href')).toBe('/partner/onboarding?request=request-1');
    expect(sharing!.text()).toContain('Shared with Northline Apparel since Nov 19');
    expect(sharing!.get('a').text()).toBe('Manage sharing');
    // The server keeps leads with onboarding history, so the drawer does not offer Delete.
    expect(started.find('button[aria-label="Delete lead"]').exists()).toBe(false);
    expect(started.text()).toContain('Leads with onboarding history are kept.');
});

it('starts onboarding from a dialog with the existing email choices and returns focus', async () => {
    const wrapper = await drawer();
    const trigger = wrapper.findAll('.drawer-status-row button').find((b) => b.text() === 'Start onboarding…')!;
    await trigger.trigger('click');
    await nextTick();
    const dialog = wrapper.get('dialog[aria-labelledby="onboarding-dialog-title"]');
    expect((dialog.element as HTMLDialogElement).open).toBe(true);
    expect(dialog.text()).toContain('Kanto Cafe linens');
    expect(document.activeElement?.getAttribute('type')).toBe('checkbox');
    await dialog.get('form').trigger('submit');
    expect(post.mock.calls[0][0]).toBe('/onboarding-requests');
    expect(post.mock.calls[0][1]).toMatchObject({ pipeline_entry_id: 'one', send_email: false });
    (dialog.element as HTMLDialogElement).close();
    await nextTick();
    expect(document.activeElement).toBe(trigger.element);
});

it('records activity with a chosen type and shows a labelled summary to viewers', async () => {
    const wrapper = await drawer();
    await wrapper.get('#lead-tab-activity').trigger('click');
    const call = wrapper
        .findAll('.activity-kind input')
        .find((input) => (input.element as HTMLInputElement).value === 'call')!;
    await call.setValue(true);
    await wrapper.get('#activity-body').setValue('Called back.');
    await wrapper.get('.activity-composer').trigger('submit');
    expect(visit.mock.calls[0][0]).toBe('/pipeline/one/activities');
    expect(visit.mock.calls[0][1].data).toMatchObject({ kind: 'call', body: 'Called back.' });
    expect(wrapper.findAll('.timeline li')).toHaveLength(2);

    const viewer = await drawer({}, false);
    expect(viewer.find('#lead-edit-form').exists()).toBe(false);
    expect(viewer.find('.drawer-footer').exists()).toBe(false);
    expect(viewer.get('.drawer-header').text()).toContain('View only');
    const summary = viewer.get('.drawer-summary');
    expect(summary.findAll('dt').map((dt) => dt.text())).toEqual(['Contact', 'Next action', 'Private notes']);
    expect(summary.text()).toContain('ana@example.test');
    expect(viewer.find('button[aria-label="Delete lead"]').exists()).toBe(false);
    expect(viewer.findAll('.drawer-status-row button')).toHaveLength(0);
});
