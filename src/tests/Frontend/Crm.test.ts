import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Crm from '../../resources/js/pages/Crm.vue';

const { get, visit } = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get, visit },
}));
let wrapper: VueWrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.clearAllMocks();
});
function render() {
    wrapper = mount(Crm, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'workspace', name: 'Studio' },
            contacts: { items: [], nextCursor: 'contacts-page-two' },
            reminders: { items: [], nextCursor: 'reminders-page-two' },
            entry: null,
            linked: [],
            canWrite: true,
            filters: { q: '', state: 'pending' },
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
    });
    return wrapper;
}
function button(name: string) {
    return wrapper.findAll('button').find((node) => node.text() === name)!;
}
it('requests segments immediately and keeps the applied state until the response arrives', async () => {
    render();
    expect(wrapper.get('section[aria-labelledby="contacts-title"] h2').attributes('id')).toBe('contacts-title');
    await button('Completed').trigger('click');
    expect(get.mock.calls[0][1]).toEqual({ q: '', state: 'completed' });
    expect(button('Pending').attributes('aria-pressed')).toBe('true');
    const options = get.mock.calls[0][2];
    options.onStart();
    await wrapper.vm.$nextTick();
    expect(button('Completed').attributes('disabled')).toBeDefined();
    expect(button('New contact').attributes('disabled')).toBeDefined();
    await button('All').trigger('click');
    expect(get).toHaveBeenCalledTimes(1);
    await wrapper.setProps({ filters: { q: '', state: 'completed' } });
    options.onFinish();
    await wrapper.vm.$nextTick();
    expect(button('Completed').attributes('aria-pressed')).toBe('true');
});
it('uses applied filters for paging and resets cursors for unapplied search edits', async () => {
    render();
    await button('More reminders').trigger('click');
    expect(get.mock.calls.at(-1)?.[1]).toEqual({ q: '', state: 'pending', reminders_cursor: 'reminders-page-two' });
    await wrapper.get('#crm-search').setValue('New search');
    await button('More contacts').trigger('click');
    expect(get.mock.calls.at(-1)?.[1]).toEqual({ q: 'New search', state: 'pending' });
    await wrapper.setProps({ filters: { q: 'Restored search', state: 'due' } });
    expect((wrapper.get('#crm-search').element as HTMLInputElement).value).toBe('Restored search');
    expect(button('Due').attributes('aria-pressed')).toBe('true');
});
