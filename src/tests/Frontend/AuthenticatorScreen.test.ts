import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import AuthenticatorScreen from '../../resources/js/features/auth/AuthenticatorScreen.vue';
import { confirmation, type Confirmation } from '../../resources/js/composables/useConfirmation';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/account/authenticator', props: {} }),
}));

type Props = InstanceType<typeof AuthenticatorScreen>['$props'];
const wrappers: VueWrapper[] = [];
function screen(props: Partial<Props> = {}) {
    const wrapper = mount(AuthenticatorScreen, {
        props: {
            accountName: 'Example Owner',
            accountEmail: 'owner@example.test',
            enrolled: false,
            verified: false,
            required: true,
            operator: false,
            ...props,
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmation.value = null;
});

// Read through a function so earlier assertions do not narrow the shared ref.
const pending = (): Confirmation | null => confirmation.value;

describe('authenticator screen', () => {
    it('asks an owner for the password before creating a setup key', async () => {
        const wrapper = screen();
        expect(wrapper.get('h1').text()).toBe('Set up an authenticator.');
        expect(wrapper.text()).toContain('Workspace owners sign in with their password');
        await wrapper.get('#password').setValue('safe-password-123');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('enroll')).toEqual([['safe-password-123']]);
        expect(wrapper.find('#code').exists()).toBe(false);
    });

    it('draws the setup key in the browser and confirms it with a code', async () => {
        const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        const request = vi.spyOn(globalThis, 'fetch');
        const wrapper = screen({ secret });
        const code = wrapper.get('svg[role="img"]');
        expect(code.attributes('aria-label')).toBe('QR code for authenticator setup');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('.wd-copy-reference code').text()).toBe(secret);
        expect(wrapper.get('#code').attributes('autocomplete')).toBe('one-time-code');
        await wrapper.get('#code').setValue('123456');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('verify')).toEqual([['123456']]);
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancel setup')!
            .trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(request).not.toHaveBeenCalled();
    });

    it('checks an enrolled account at sign-in and offers a recovery code, except to operators', async () => {
        const wrapper = screen({ enrolled: true });
        expect(wrapper.get('h1').text()).toBe('Enter your authenticator code.');
        expect(wrapper.find('#password').exists()).toBe(false);
        await wrapper.get('#code').setValue('654321');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('verify')).toEqual([['654321']]);

        const toggle = wrapper.findAll('button').find((button) => button.text() === 'Use a recovery code instead')!;
        await toggle.trigger('click');
        expect(document.activeElement?.id).toBe('recovery_code');
        await wrapper.get('#recovery_code').setValue('ABCDE-FGHIJ');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('recover')).toEqual([['ABCDE-FGHIJ']]);
        expect(wrapper.text()).toContain('an administrator can reset your authenticator');

        const operator = screen({ enrolled: true, operator: true });
        expect(operator.text()).not.toContain('Use a recovery code instead');
        expect(operator.text()).toContain('performed offline by an authorized operator');
    });

    it('associates server errors with their fields and moves focus there', async () => {
        const wrapper = screen({ enrolled: true });
        await wrapper.setProps({ errors: { code: 'Enter a fresh authenticator code.' } });
        expect(wrapper.get('#code').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#code-error').attributes('role')).toBe('alert');
        expect(document.activeElement?.id).toBe('code');
        await wrapper.setProps({ processing: true });
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
    });

    it('shows new recovery codes once with a warning and a way to continue', () => {
        const codes = ['AAAAA-BBBBB', 'CCCCC-DDDDD'];
        const wrapper = screen({ enrolled: true, verified: true, recoveryCodes: codes, continueTo: '/portal/enter' });
        expect(wrapper.get('h1').text()).toBe('Save your recovery codes.');
        expect(wrapper.findAll('.wd-recovery-codes code').map((code) => code.text())).toEqual(codes);
        expect(wrapper.get('[role="note"]').text()).toContain('shown only now');
        expect(wrapper.get('a.wd-button-primary').attributes('href')).toBe('/portal/enter');

        const operator = screen({ enrolled: true, verified: true, operator: true, recoveryCodes: [] });
        expect(operator.find('.wd-recovery-codes').exists()).toBe(false);
        expect(operator.text()).toContain('have no recovery codes');
    });

    it('requires confirmation before replacing the authenticator or the recovery codes', async () => {
        const wrapper = screen({ enrolled: true, verified: true, recoveryRemaining: 2, continueTo: '/workspaces' });
        expect(wrapper.get('[role="note"]').text()).toContain('Only 2 recovery codes are left');
        const button = (label: string) => wrapper.findAll('button').find((item) => item.text() === label)!;

        await button('Replace authenticator').trigger('click');
        await wrapper.get('#password').setValue('safe-password-123');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('enroll')).toBeUndefined();
        expect(pending()?.title).toBe('Replace authenticator');
        expect(pending()?.description).toContain('Example Owner');
        expect(pending()?.tone).toBe('danger');
        pending()?.action();
        expect(wrapper.emitted('enroll')).toEqual([['safe-password-123']]);
        confirmation.value = null;

        await button('Cancel').trigger('click');
        await button('New recovery codes').trigger('click');
        await wrapper.get('#password').setValue('safe-password-123');
        await wrapper.get('#code').setValue('123456');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('regenerate')).toBeUndefined();
        expect(pending()?.description).toContain('stop working immediately');
        pending()?.action();
        expect(wrapper.emitted('regenerate')).toEqual([['safe-password-123', '123456']]);
    });
});
