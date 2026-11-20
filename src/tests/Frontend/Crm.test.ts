import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Crm from '../../resources/js/pages/Crm.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import { dueLabel, reminderTone, snoozePresets } from '../../resources/js/features/crm/snooze';

const { get, visit } = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get, visit },
}));
let wrapper: VueWrapper;
afterEach(() => {
    wrapper?.unmount();
    confirmation.value = null;
    vi.clearAllMocks();
    vi.useRealTimers();
});
const contact = (id: string, name: string, lead_count = 0) => ({
    id,
    name,
    email: `${id}@example.test`,
    company: '',
    notes: '',
    version: 2,
    lead_count,
});
function render(overrides: Record<string, unknown> = {}) {
    wrapper = mount(Crm, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'workspace', name: 'Studio' },
            contacts: { items: [contact('c1', 'Ana'), contact('c2', 'Ben', 1)], page: 2, perPage: 10, total: 32 },
            reminders: { items: [], page: 1, perPage: 25, total: 0 },
            summary: { contacts: 32, overdue: 3, dueDay: 1, dueWeek: 4 },
            entry: null,
            linked: [],
            canWrite: true,
            filters: { q: '', state: 'pending', sort: 'name_asc', linked: '', per_page: '10' },
            ...overrides,
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
        attachTo: document.body,
    });
    return wrapper;
}
function button(name: string) {
    return wrapper.findAll('button').find((node) => node.text() === name)!;
}
it('changes the reminder filter at once, keeps the contacts page and holds the applied state until the response', async () => {
    render();
    expect(wrapper.get('section[aria-labelledby="contacts-title"] h2').text()).toBe('Contacts');
    await button('Completed').trigger('click');
    expect(get.mock.calls[0][1]).toEqual({ state: 'completed', sort: 'name_asc', per_page: '10', contacts_page: '2' });
    expect(button('Pending').attributes('aria-pressed')).toBe('true');
    const options = get.mock.calls[0][2];
    options.onStart();
    await wrapper.vm.$nextTick();
    expect(button('Completed').attributes('disabled')).toBeDefined();
    await button('All').trigger('click');
    expect(get).toHaveBeenCalledTimes(1);
    await wrapper.setProps({ filters: { q: '', state: 'completed', sort: 'name_asc', linked: '', per_page: '10' } });
    options.onFinish();
    await wrapper.vm.$nextTick();
    expect(button('Completed').attributes('aria-pressed')).toBe('true');
});
it('pages with numbered links, returns contact filter changes to page 1 and searches after typing', async () => {
    vi.useFakeTimers();
    render();
    const pages = wrapper.get('nav[aria-label="Contacts pages"]');
    expect(pages.text()).toContain('Showing 11–12 of 32 matching contacts');
    expect(pages.get('a[aria-label="Page 3"]').attributes('href')).toBe(
        '/partner/contacts?state=pending&sort=name_asc&per_page=10&contacts_page=3',
    );
    await wrapper.get('#crm-per-page').setValue('25');
    expect(get.mock.calls.at(-1)?.[1]).toEqual({ state: 'pending', sort: 'name_asc', per_page: '25' });
    get.mock.calls.at(-1)?.[2].onFinish();
    await wrapper.get('#crm-search').setValue('  Ana ');
    expect(get).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(400);
    expect(get.mock.calls.at(-1)?.[1]).toEqual({ q: 'Ana', state: 'pending', sort: 'name_asc', per_page: '10' });
});
it('selects contacts and confirms a bulk delete with the selected versions', async () => {
    render();
    expect(wrapper.find('[aria-label="Selected contacts"]').exists()).toBe(false);
    await wrapper.get('input[aria-label="Select all contacts on this page"]').setValue(true);
    expect(wrapper.get('[aria-label="Selected contacts"]').text()).toContain('2 contacts selected');
    expect(wrapper.get('a[download][href^="/crm/exports/contacts"]').attributes('href')).toBe(
        '/crm/exports/contacts?ids%5B%5D=c1&ids%5B%5D=c2',
    );
    await button('Delete…').trigger('click');
    expect(confirmation.value?.title).toBe('Delete contacts');
    expect(visit).not.toHaveBeenCalled();
    confirmation.value!.action();
    expect(visit.mock.calls[0][0]).toBe('/contacts/bulk');
    expect(visit.mock.calls[0][1].data).toMatchObject({
        action: 'delete',
        items: [
            { id: 'c1', version: 2 },
            { id: 'c2', version: 2 },
        ],
        crm_filters: { state: 'pending', sort: 'name_asc', per_page: '10', contacts_page: '2' },
    });
    visit.mock.calls[0][1].onSuccess();
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[aria-label="Selected contacts"]').exists()).toBe(false);
});
it('offers bulk linking only for contacts not yet linked to the lead in context', async () => {
    render({ entry: { id: 'lead', brand_name: 'Acme', version: 4 }, linked: [{ id: 'c2', name: 'Ben' }] });
    expect(wrapper.get('[aria-labelledby="linked-title"]').text()).toContain('Ben');
    await wrapper.get('input[aria-label="Select Ben"]').setValue(true);
    expect(button('Link to Acme').attributes('disabled')).toBeDefined();
    await wrapper.get('input[aria-label="Select Ana"]').setValue(true);
    await button('Link to Acme').trigger('click');
    expect(visit.mock.calls[0][1].data).toMatchObject({
        action: 'link',
        items: [{ id: 'c1', version: 2 }],
        link_entry: 'lead',
        entry_version: 4,
        entry: 'lead',
    });
});
it('shows workspace figures and links overdue reminders to the due filter', () => {
    render();
    const stats = wrapper.get('[aria-label="Workspace summary"]');
    expect(stats.text()).toContain('Overdue reminders3');
    expect(stats.get('a').attributes('href')).toBe(
        '/partner/contacts?state=due&sort=name_asc&per_page=10&contacts_page=2',
    );
});
it('groups reminders by urgency and hides write actions from viewers', () => {
    const now = Date.now();
    render({
        canWrite: false,
        reminders: {
            items: [
                {
                    id: 'r1',
                    title: 'Late',
                    due_at: new Date(now - 3600_000).toISOString(),
                    completed_at: null,
                    pipeline_entry_id: 'lead',
                    lead_name: 'Acme',
                    version: 1,
                },
                {
                    id: 'r2',
                    title: 'Later',
                    due_at: new Date(now + 9 * 86400_000).toISOString(),
                    completed_at: null,
                    pipeline_entry_id: null,
                    lead_name: null,
                    version: 1,
                },
            ],
            page: 1,
            perPage: 25,
            total: 2,
        },
    });
    expect(wrapper.findAll('h3.crm-group').map((node) => node.text())).toEqual(['Overdue', 'Upcoming']);
    expect(wrapper.find('a[aria-label="Open related lead Acme"]').exists()).toBe(true);
    expect(wrapper.find('button[aria-label="Complete: Late"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Snooze Late"]').exists()).toBe(false);
    expect(button('New contact')).toBeUndefined();
});
it('computes future snooze presets and urgency in local time', () => {
    const monday = new Date(2026, 10, 23, 22, 15);
    const [later, tomorrow, nextWeek] = snoozePresets(monday);
    expect(later!.at.getTime()).toBe(new Date(2026, 10, 24, 1, 0).getTime());
    expect(tomorrow!.at.getTime()).toBe(new Date(2026, 10, 24, 9, 0).getTime());
    expect(nextWeek!.at.getTime()).toBe(new Date(2026, 10, 30, 9, 0).getTime());
    const friday = new Date(2026, 10, 20, 8, 0);
    expect(snoozePresets(friday)[2]!.at.getTime()).toBe(new Date(2026, 10, 23, 9, 0).getTime());
    expect(reminderTone({ due_at: new Date(2026, 10, 20, 18).toISOString(), completed_at: null }, friday)).toBe(
        'today',
    );
    expect(reminderTone({ due_at: new Date(2026, 10, 19).toISOString(), completed_at: null }, friday)).toBe('overdue');
    expect(dueLabel(new Date(2025, 0, 2, 9).toISOString(), friday)).toContain('2025');
});
