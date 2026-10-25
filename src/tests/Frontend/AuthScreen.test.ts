import { afterEach, describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import AuthScreen from '../../resources/js/features/auth/AuthScreen.vue';

const wrappers: VueWrapper[] = [];
function screen(mode: 'login' | 'register' | 'forgot-password' | 'reset-password' | 'verify-email' = 'login') {
    const wrapper = mount(AuthScreen, { props: { mode }, attachTo: document.body });
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('authentication screens', () => {
    it('submits login credentials without unrelated registration fields', async () => {
        const wrapper = screen();
        await wrapper.get('#email').setValue('creator@example.test');
        await wrapper.get('#password').setValue('safe-password-123');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    mode: 'login',
                    email: 'creator@example.test',
                    password: 'safe-password-123',
                },
            ],
        ]);
        expect(wrapper.get('#password').attributes('autocomplete')).toBe('current-password');
    });

    it('collects password confirmation for registration and explains the password rule', async () => {
        const wrapper = screen('register');
        await wrapper.get('#name').setValue('Creator');
        await wrapper.get('#email').setValue('creator@example.test');
        await wrapper.get('#password').setValue('safe-password-123');
        await wrapper.get('#password_confirmation').setValue('safe-password-123');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('submit')).toEqual([
            [
                {
                    mode: 'register',
                    name: 'Creator',
                    email: 'creator@example.test',
                    password: 'safe-password-123',
                    password_confirmation: 'safe-password-123',
                },
            ],
        ]);
        expect(wrapper.get('#password').attributes('aria-describedby')).toBe('password-hint');
        expect(wrapper.get('#password-hint').text()).toContain('12 characters');
    });

    it('moves focus to server validation errors and associates the error with the input', async () => {
        const wrapper = screen();
        await wrapper.setProps({ errors: { email: 'These credentials do not match our records.' } });
        expect(wrapper.get('#email').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#email').attributes('aria-describedby')).toBe('email-error');
        expect(wrapper.get('#email-error').attributes('role')).toBe('alert');
        expect(document.activeElement).toBe(wrapper.get('#email').element);
    });

    it('prevents duplicate submissions while a request is pending', async () => {
        const wrapper = screen();
        await wrapper.setProps({ processing: true });
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.get('fieldset').attributes()).toHaveProperty('disabled');
        expect(wrapper.get('form').attributes('aria-busy')).toBe('true');
    });

    it('focuses errors after the pending fieldset becomes enabled', async () => {
        const wrapper = screen();
        await wrapper.setProps({ processing: true, errors: { email: 'Try again.' } });
        expect(document.activeElement).not.toBe(wrapper.get('#email').element);
        await wrapper.setProps({ processing: false });
        expect(document.activeElement).toBe(wrapper.get('#email').element);
    });

    it('clears passwords when changing flows and only submits email for recovery', async () => {
        const wrapper = screen();
        await wrapper.get('#email').setValue('creator@example.test');
        await wrapper.get('#password').setValue('do-not-retain');
        await wrapper.setProps({ mode: 'forgot-password' });
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('submit')).toEqual([[{ mode: 'forgot-password', email: 'creator@example.test' }]]);
        await wrapper.setProps({ mode: 'login' });
        expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('');
    });

    it('keeps verification resend and sign-out actions distinct', async () => {
        const wrapper = screen('verify-email');
        await wrapper.setProps({ email: 'creator@example.test', status: 'A fresh verification link was requested.' });
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('.wd-status').exists()).toBe(false);
        expect(wrapper.text()).toContain('creator@example.test');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('resendVerification')).toEqual([[]]);
        expect(wrapper.emitted('submit')).toBeUndefined();
        await wrapper.get('nav button').trigger('click');
        expect(wrapper.emitted('logout')).toEqual([[]]);
    });
});
