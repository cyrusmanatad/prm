import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import JoinLinkPanel from '../../resources/js/features/portals/JoinLinkPanel.vue';

const { post, destroy, promise } = vi.hoisted(() => ({ post: vi.fn(), destroy: vi.fn(), promise: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { post, delete: destroy } }));
vi.mock('../../resources/js/composables/useNotify', () => ({ useNotify: () => ({ promise }) }));
const link = { id: 'link-1', code: '01234-56789', url: 'http://localhost/join#01234-56789' };
afterEach(() => vi.clearAllMocks());
describe('brand sign-up controls', () => {
    it('creates only after a click and includes the expected inactive version', async () => {
        const wrapper = mount(JoinLinkPanel, { props: { joinLink: null } });
        expect(post).not.toHaveBeenCalled();
        await wrapper.get('button').trigger('click');
        expect(post).toHaveBeenCalledWith('/partner-join-link', { link_id: null }, expect.any(Object));
        const options = post.mock.calls[0][2];
        options.onStart();
        await wrapper.vm.$nextTick();
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        options.onFinish();
        await wrapper.vm.$nextTick();
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
    });
    it('requests confirmation with code and consequence before replacing or disabling', async () => {
        const wrapper = mount(JoinLinkPanel, { props: { joinLink: link } });
        const buttons = wrapper.findAll('button');
        await buttons[2].trigger('click');
        expect(post).not.toHaveBeenCalled();
        const replacement = wrapper.emitted('confirm')![0];
        expect(replacement[1]).toContain(link.code);
        expect(replacement[1]).toContain('Existing partnerships stay active');
        (replacement[2] as () => void)();
        expect(post).toHaveBeenCalledWith('/partner-join-link', { link_id: link.id }, expect.any(Object));
        await buttons[3].trigger('click');
        const disable = wrapper.emitted('confirm')![1];
        (disable[2] as () => void)();
        expect(destroy).toHaveBeenCalledWith(
            '/partner-join-link',
            expect.objectContaining({ data: { link_id: link.id } }),
        );
    });
    it('provides selectable labelled fields and manual-copy recovery instructions', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
            configurable: true,
        });
        const wrapper = mount(JoinLinkPanel, { props: { joinLink: link } });
        await wrapper.findAll('button')[0].trigger('click');
        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(link.url);
        expect(promise).toHaveBeenCalledWith(
            expect.any(Promise),
            expect.objectContaining({ error: expect.stringContaining('copy it manually') }),
            'join-link-copy',
        );
        expect(wrapper.get('#join-link-url').attributes('readonly')).toBeDefined();
        expect(wrapper.get('label[for="join-link-code"]').text()).toBe('Sign-up code');
    });
});
