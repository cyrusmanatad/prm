import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import Portal from '../../resources/js/pages/Portal.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    router: { post: vi.fn(), delete: vi.fn() },
    useForm: () => ({ email: '', send_email: false, errors: {}, clearErrors: vi.fn(), reset: vi.fn() }),
}));
vi.mock('../../resources/js/composables/useNotify', () => ({ useNotify: () => ({ promise: vi.fn() }) }));
let wrapper: VueWrapper;
beforeEach(() => {
    HTMLDialogElement.prototype.showModal = function () {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function () {
        this.open = false;
    };
    wrapper = mount(Portal, {
        attachTo: document.body,
        props: {
            account: { name: 'Brand owner', email: 'brand@example.test' },
            workspace: { id: 'brand', name: 'Shared Brand', kind: 'brand' },
            relationships: [],
            canManage: true,
            pipeline: null,
            portal: 'brand',
            invitations: [],
            joinLink: { id: 'link', code: '01234-56789', url: 'http://localhost/join#01234-56789' },
        },
        global: {
            stubs: {
                BrandDashboard: { template: '<button @click="$emit(\'invite\')">Invite</button>', emits: ['invite'] },
            },
        },
    });
});
afterEach(() => {
    wrapper.unmount();
    confirmation.value = null;
    document.body.innerHTML = '';
});

it('keeps the invitation dialog and trigger focus when a stale native close arrives after confirmation cancellation', async () => {
    await wrapper.get('button').trigger('click');
    await flushPromises();
    const replace = wrapper.findAll('button').find((button) => button.text() === 'Replace link')!;
    await replace.trigger('click');
    await flushPromises();
    const dialog = wrapper.get('dialog').element as HTMLDialogElement;
    expect(dialog.open).toBe(false);
    expect(confirmation.value?.title).toBe('Replace sign-up link');
    confirmation.value = null;
    await flushPromises();
    expect(dialog.open).toBe(true);
    expect(document.activeElement).toBe(replace.element);
    dialog.dispatchEvent(new Event('close'));
    await flushPromises();
    expect(dialog.open).toBe(true);
    expect(document.activeElement).toBe(replace.element);
});
