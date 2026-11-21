import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import Automation from '../../resources/js/pages/Automation.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';

type Call = { method: string; url: string; data: Record<string, unknown> };
const { calls, page } = vi.hoisted(() => ({ calls: [] as Call[], page: { url: '/automation' } }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: make } = await import('vue');
    return {
        Head: { template: '<span />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { get: vi.fn(), post: vi.fn() },
        usePage: () => page,
        useForm: (data: Record<string, unknown>) => {
            let defaults = { ...data };
            let transform = (values: Record<string, unknown>) => values;
            const form = make({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                transform(fn: typeof transform) {
                    transform = fn;
                    return form;
                },
                reset() {
                    Object.assign(form, defaults);
                },
                defaults(values: Record<string, unknown>) {
                    defaults = { ...values };
                },
                clearErrors() {
                    form.errors = {};
                },
                setError(key: string, message: string) {
                    form.errors = { ...form.errors, [key]: message };
                },
            });
            for (const method of ['post', 'put', 'patch', 'delete'])
                (form as Record<string, unknown>)[method] = (url: string) => {
                    const values = Object.fromEntries(
                        Object.keys(data).map((key) => [key, (form as Record<string, unknown>)[key]]),
                    );
                    calls.push({ method, url, data: transform(values) });
                };
            return form;
        },
    };
});
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event('close'));
};

const draft = (id: string, subject: string, state: string, extra: Record<string, unknown> = {}) => ({
    id,
    pipeline_entry_id: 'lead-1',
    lead_name: 'Harbor Studio',
    recipient: 'alex@example.test',
    subject,
    body: `${subject} body`,
    version: state === 'draft' ? 1 : 2,
    state,
    outreach_state: null,
    schedule_run_id: null,
    created_at: '2026-11-21T10:00:00Z',
    ...extra,
});
const preferences = {
    timezone: 'Asia/Manila',
    reminders: true,
    weekly_digest: true,
    follow_up_drafts: false,
    follow_up_reviews: false,
    version: 3,
};
const weekly = {
    id: 'weekly-1',
    name: 'Monday review',
    kind: 'weekly_digest',
    version: 2,
    enabled: true,
    timezone: 'Asia/Manila',
    weekday: 1,
    local_time: '09:00',
    next_due_at: '2026-11-23T01:00:00Z',
    subject_label: null,
    subject_lead: null,
};
let wrapper: VueWrapper;
beforeEach(() => {
    page.url = '/automation';
    history.replaceState(null, '', '/automation');
});
afterEach(() => {
    wrapper?.unmount();
    confirmation.value = null;
    calls.splice(0);
});
function render(overrides: Record<string, unknown> = {}) {
    wrapper = mount(Automation, {
        props: reactive<any>({
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'workspace', name: 'Studio' },
            preferences,
            schedules: [
                weekly,
                {
                    ...weekly,
                    id: 'reminder-1',
                    name: 'Proposal reminder',
                    kind: 'reminder',
                    weekday: null,
                    local_time: null,
                    subject_label: 'Send revised proposal',
                },
            ],
            reminders: [{ id: 'r1', title: 'Send revised proposal', due_at: '2026-11-23T00:00:00Z' }],
            drafts: [draft('d1', 'Checking in', 'draft'), draft('d2', 'Next steps', 'approved')],
            leads: [{ id: 'lead-1', brand_name: 'Harbor Studio' }],
            notices: [],
            timezones: ['Asia/Manila', 'UTC'],
            canWrite: true,
            ...overrides,
        }),
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
        attachTo: document.body,
    });
    return wrapper;
}
const tab = (name: string) => wrapper.findAll('[role="tab"]').find((node) => node.text().startsWith(name))!;
const panel = (id: string) => wrapper.get(`#auto-panel-${id}`);

it('opens on Approvals with drafts needing review and keeps the tab in the address', async () => {
    render();
    expect(tab('Approvals').attributes('aria-selected')).toBe('true');
    expect(tab('Approvals').text()).toContain('1 to review');
    expect(tab('Schedules').text()).toContain('2');
    const rows = () => panel('approvals').findAll('tbody tr');
    expect(rows()).toHaveLength(1);
    expect(rows()[0]!.text()).toContain('Checking in');
    expect(rows()[0]!.text()).toContain('Harbor Studio');
    expect(rows()[0]!.find('[aria-label="Approve Checking in"]').exists()).toBe(true);
    await tab('Schedules').trigger('click');
    expect(window.location.search).toBe('?tab=schedules');
    expect(panel('schedules').text()).toContain('Every Monday at 09:00');
    expect(panel('schedules').text()).toContain('Send revised proposal');
    expect(panel('schedules').find('[aria-label="Edit Proposal reminder"]').exists()).toBe(false);
    await tab('Approvals').trigger('click');
    expect(window.location.search).toBe('');
});

it('reads the tab and open draft from the address and shows the draft in a drawer', async () => {
    page.url = '/professional/automation?tab=history&draft=d2';
    render();
    expect(tab('Email history').attributes('aria-selected')).toBe('true');
    await flushPromises();
    const drawer = wrapper.get('dialog.auto-drawer');
    expect(drawer.text()).toContain('Approved for manual sending · Version 2');
    expect(drawer.get('a[download]').attributes('href')).toBe('/automation/drafts/d2/handoff?version=2');
    expect(drawer.findAll('button').some((button) => button.text() === 'Send approved message')).toBe(true);
    expect(drawer.find('[aria-label="Approve Next steps"]').exists()).toBe(false);
});

it('filters drafts and confirms Approve before posting the current version', async () => {
    render();
    const chip = (label: string) =>
        panel('approvals')
            .findAll('.wd-filter-chips button')
            .find((node) => node.text().startsWith(label))!;
    await chip('All').trigger('click');
    expect(panel('approvals').findAll('tbody tr')).toHaveLength(2);
    await chip('Closed').trigger('click');
    expect(panel('approvals').text()).toContain('No closed drafts');
    await chip('Needs review').trigger('click');
    await panel('approvals').get('[aria-label="Approve Checking in"]').trigger('click');
    expect(confirmation.value?.title).toBe('Approve follow-up');
    expect(calls).toHaveLength(0);
    confirmation.value!.action();
    expect(calls[0]).toMatchObject({ method: 'post', url: '/automation/drafts/d1/approve', data: { version: 1 } });
});

it('edits a draft in the drawer with Save disabled until the message changes', async () => {
    render();
    await panel('approvals').get('button.auto-row-title').trigger('click');
    await flushPromises();
    expect(window.location.search).toBe('?draft=d1');
    const drawer = () => wrapper.get('dialog.auto-drawer');
    await drawer().get('[aria-label="Edit Checking in"]').trigger('click');
    const save = () =>
        drawer()
            .findAll('button')
            .find((button) => button.text() === 'Save draft')!;
    expect(save().attributes('disabled')).toBeDefined();
    await drawer().get('#edit-draft-body').setValue('A changed message');
    expect(drawer().text()).toContain('Unsaved changes');
    expect(save().attributes('disabled')).toBeUndefined();
    await drawer().get('form').trigger('submit');
    expect(calls[0]).toMatchObject({
        method: 'patch',
        url: '/automation/drafts/d1',
        data: { recipient: 'alex@example.test', subject: 'Checking in', body: 'A changed message', version: 1 },
    });
    expect(calls[0]!.data).not.toHaveProperty('pipeline_entry_id');
});

it('explains blocked schedule types and saves a reminder email with an exact instant', async () => {
    render();
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'New schedule')!
        .trigger('click');
    const dialog = () => wrapper.findAll('dialog.auto-dialog')[0]!;
    const save = () =>
        dialog()
            .findAll('button')
            .find((button) => button.text() === 'Save schedule')!;
    expect(dialog().text()).toContain('You already have an active weekly digest, “Monday review”.');
    expect(save().attributes('disabled')).toBeDefined();
    await dialog().get('#schedule-kind-follow_up_draft').trigger('click');
    expect(dialog().text()).toContain('Follow-up draft notices are off in your preferences.');
    expect(save().attributes('disabled')).toBeDefined();
    await dialog().get('#schedule-kind-reminder').trigger('click');
    expect(save().attributes('disabled')).toBeUndefined();
    await dialog().get('#schedule-name').setValue('Proposal email');
    await dialog().get('#schedule-due').setValue('2026-12-01T09:30');
    await dialog().get('form').trigger('submit');
    const expected = new Date('2026-12-01T09:30').toISOString().replace(/\.\d{3}Z$/, '+00:00');
    expect(calls[0]).toMatchObject({
        method: 'post',
        url: '/automation/schedules',
        data: { name: 'Proposal email', kind: 'reminder', due_at: expected },
    });
    expect(calls[0]!.data).not.toHaveProperty('weekday');
});

it('keeps preferences unsaved until Save and confirms turning a notice off', async () => {
    render();
    await tab('Preferences').trigger('click');
    const save = () =>
        panel('preferences')
            .findAll('button')
            .find((button) => button.text() === 'Save preferences')!;
    expect(save().attributes('disabled')).toBeDefined();
    await panel('preferences').get('#automation-weekly_digest').setValue(false);
    expect(panel('preferences').text()).toContain('Unsaved changes');
    await panel('preferences').get('form').trigger('submit');
    expect(confirmation.value?.title).toBe('Withdraw notice consent');
    expect(calls).toHaveLength(0);
    confirmation.value!.action();
    expect(calls[0]).toMatchObject({ method: 'put', url: '/automation/preferences', data: { weekly_digest: false } });
    await panel('preferences')
        .findAll('button')
        .find((button) => button.text() === 'Discard')!
        .trigger('click');
    expect((panel('preferences').get('#automation-weekly_digest').element as HTMLInputElement).checked).toBe(true);
});

it('gives viewers read-only lists without create or row actions', () => {
    render({ canWrite: false });
    expect(wrapper.text()).toContain('Viewer access');
    expect(wrapper.findAll('button').some((button) => button.text() === 'Write follow-up')).toBe(false);
    expect(panel('approvals').find('[aria-label="Approve Checking in"]').exists()).toBe(false);
    expect(panel('schedules').find('[aria-label="Withdraw consent for Monday review"]').exists()).toBe(false);
    expect(
        panel('preferences')
            .findAll('button')
            .some((button) => button.text() === 'Save preferences'),
    ).toBe(false);
});
