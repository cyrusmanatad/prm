import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import JoinBrand from '../../resources/js/pages/JoinBrand.vue';
const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post, reset: vi.fn() }),
}));
const base = { account: null, brand: null, workspace: null, verified: false };
const options = { global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } } };
beforeEach(() => {
    history.replaceState(null, '', '/join');
    post.mockClear();
});
afterEach(() => {
    history.replaceState(null, '', '/');
});
describe('joining a brand', () => {
    it('removes fragments before exchanging them and handles malformed encoding', async () => {
        history.replaceState(null, '', '/join#01234-56789');
        mount(JoinBrand, { props: base, ...options });
        await flushPromises();
        expect(location.hash).toBe('');
        expect(post).toHaveBeenCalledWith('/join/prepare', expect.any(Object));
    });
    it('provides code entry, guest registration, verification and workspace recovery', async () => {
        const wrapper = mount(JoinBrand, { props: base, ...options });
        expect(wrapper.get('label[for="join-code"]').text()).toBe('Sign-up code');
        await wrapper.setProps({ brand: 'Brand' });
        expect(wrapper.get('a[href="/partner/register"]').text()).toBe('Create account');
        await wrapper.setProps({ account: { name: 'Owner', email: 'owner@example.test' } });
        expect(wrapper.get('a[href="/verify-email"]').text()).toBe('Verify email');
        await wrapper.setProps({ verified: true });
        expect(wrapper.get('a[href="/join/select"]').text()).toBe('Choose workspace');
        expect(post).not.toHaveBeenCalled();
    });
    it('shows selected workspace and explicit consent with persistent actionable errors', async () => {
        const wrapper = mount(JoinBrand, {
            props: {
                ...base,
                account: { name: 'Owner', email: 'owner@example.test' },
                brand: 'Brand',
                verified: true,
                workspace: { name: 'Consulting', experience: 'professional' },
                errors: { code: 'Open the brand link again.' },
            },
            ...options,
        });
        expect(wrapper.text()).toContain('Consulting');
        expect(wrapper.text()).toContain('Professional');
        expect(wrapper.get('a[href="/workspaces"]').text()).toBe('Change workspace');
        expect(wrapper.get('#join-consent').attributes('required')).toBeDefined();
        expect((wrapper.get('#join-consent').element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain('Open the brand link again');
        expect(post).not.toHaveBeenCalled();
        await wrapper.get('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/join/accept');
    });
});
