import { confirmation } from '../../resources/js/composables/useConfirmation';
import { afterEach, describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import WorkspaceScreen from '../../resources/js/features/workspaces/WorkspaceScreen.vue';
import type { AccountSecurityStatus, WorkspaceSummary } from '../../resources/js/types/foundation';

HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event('close'));
};
const workspaces: WorkspaceSummary[] = [
    { id: 'workspace-a', name: 'Creator Studio', role: 'owner', kind: 'creator', experience: 'partner' },
    { id: 'workspace-b', name: 'Shared Studio', role: 'viewer', kind: 'creator', experience: 'professional' },
    { id: 'workspace-c', name: 'Northline', role: 'manager', kind: 'brand' },
];
const wrappers: VueWrapper[] = [];
function screen(spaces = workspaces, security?: AccountSecurityStatus) {
    const wrapper = mount(WorkspaceScreen, {
        props: {
            account: { name: 'Creator', email: 'creator@example.test' },
            workspaces: spaces,
            activeOrgId: spaces[0]?.id,
            ...(security ? { security } : {}),
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    confirmation.value = null;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    history.replaceState(null, '', '/');
});

describe('workspace screens', () => {
    it('shows the first-workspace form on the page and submits a trimmed name', async () => {
        const wrapper = screen([]);
        expect(wrapper.text()).toContain('You don’t have an active workspace yet');
        expect(wrapper.find('.ws-header button').exists()).toBe(false);
        await wrapper.get('#workspace_name').setValue('  My Studio  ');
        await wrapper.get('.ws-first form').trigger('submit');
        expect(wrapper.emitted('create')).toEqual([['My Studio', 'creator', 'partner']]);
    });

    it('lists workspaces as cards with type, role, the current one and switch actions', async () => {
        const wrapper = screen();
        const cards = wrapper.findAll('.ws-item');
        expect(cards).toHaveLength(3);
        expect(cards[0]!.classes()).toContain('is-current');
        expect(cards[0]!.text()).toContain('Current');
        expect(cards[0]!.get('a').text()).toBe('Open partner portal');
        expect(cards[1]!.text()).toContain('Professional');
        expect(cards[1]!.text()).toContain('Viewer');
        expect(cards[2]!.text()).toContain('Brand');
        await cards[1]!.get('button[aria-label="Switch to Shared Studio, Professional workspace"]').trigger('click');
        expect(wrapper.emitted('select')).toEqual([['workspace-b']]);
        await wrapper.setProps({ activeOrgId: 'workspace-c' });
        expect(wrapper.findAll('.ws-item')[2]!.get('a').text()).toBe('Open brand hub');
    });

    it('shows owner tools and rename, and removes them for a viewer workspace', async () => {
        const wrapper = screen();
        const tools = wrapper.findAll('.ws-tools a');
        expect(tools.map((link) => link.attributes('href'))).toEqual([
            '/automation',
            '/finance/invoices',
            '/workspace/entitlements',
            '/workspace/support-access',
        ]);
        expect(wrapper.get('.ws-tools').text()).toContain('Owner · Invoices and payment records');
        const renameButton = wrapper.findAll('.ws-current-side button').find((button) => button.text() === 'Rename');
        await renameButton!.trigger('click');
        await nextTick();
        const dialog = wrapper.get('dialog[aria-labelledby="rename-dialog-title"]');
        expect((dialog.element as HTMLDialogElement).open).toBe(true);
        expect((wrapper.get('#current_workspace_name').element as HTMLInputElement).value).toBe('Creator Studio');
        expect(document.activeElement).toBe(wrapper.get('#current_workspace_name').element);
        await wrapper.get('#current_workspace_name').setValue('Updated Studio');
        await dialog.get('form').trigger('submit');
        expect(wrapper.emitted('rename')).toEqual([['Updated Studio']]);
        (wrapper.vm as unknown as { closeRename: () => void }).closeRename();
        await nextTick();
        expect(document.activeElement).toBe(renameButton!.element);

        await wrapper.setProps({ activeOrgId: 'workspace-b' });
        expect(wrapper.findAll('.ws-tools a').map((link) => link.attributes('href'))).toEqual(['/automation']);
        expect(wrapper.find('.ws-current-side button').exists()).toBe(false);
        expect(wrapper.get('.ws-current').text()).toContain('viewer role does not include');
    });

    it('creates further workspaces from a dialog, with Brand as a reviewed request', async () => {
        const wrapper = screen();
        expect(wrapper.find('#workspace_name').exists()).toBe(false);
        const trigger = wrapper.get('.ws-header button');
        expect(trigger.text()).toBe('New workspace');
        await trigger.trigger('click');
        await nextTick();
        const dialog = wrapper.get('dialog[aria-labelledby="workspace-dialog-title"]');
        expect((dialog.element as HTMLDialogElement).open).toBe(true);
        expect(document.activeElement).toBe(dialog.get('input[value="creator"]').element);
        await dialog.get('input[value="brand"]').setValue(true);
        expect(dialog.find('#workspace_name').exists()).toBe(false);
        expect(dialog.get('a[href="/brand/requests"]').text()).toBe('Request Brand workspace');
        await dialog.get('form').trigger('submit');
        expect(wrapper.emitted('create')).toBeUndefined();
        await dialog.get('input[value="professional"]').setValue(true);
        await dialog.get('#workspace_name').setValue('Solo');
        await dialog.get('form').trigger('submit');
        expect(wrapper.emitted('create')).toEqual([['Solo', 'creator', 'professional']]);
        (wrapper.vm as unknown as { clearCreateForm: () => void }).clearCreateForm();
        await nextTick();
        expect((dialog.element as HTMLDialogElement).open).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
    });

    it('hides revoked workspace settings and offers a new selection', async () => {
        const wrapper = screen();
        await wrapper.setProps({ accessRevoked: true });
        expect(wrapper.get('[role="alert"]').text()).toContain('access to the previous workspace has changed');
        expect(wrapper.find('.ws-current').exists()).toBe(false);
        expect(wrapper.findAll('.ws-item.is-current')).toHaveLength(0);
        expect(wrapper.findAll('.ws-item button')).toHaveLength(3);
    });

    it('prevents overlapping workspace mutations', async () => {
        const wrapper = screen();
        await wrapper.setProps({ pendingAction: 'create' });
        expect(wrapper.get('.ws-header button').attributes()).toHaveProperty('disabled');
        expect(wrapper.get('.ws-item button').attributes()).toHaveProperty('disabled');
        await wrapper.get('.ws-item button').trigger('click');
        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('flags a missing required authenticator and keeps the security tab in the address', async () => {
        const wrapper = screen(workspaces, { enrolled: false, required: true });
        const tab = wrapper.get('#ws-tab-security');
        expect(tab.text()).toContain('Action needed');
        expect(wrapper.get('#ws-panel-security').isVisible()).toBe(false);
        await tab.trigger('click');
        expect(window.location.search).toBe('?tab=security');
        expect(wrapper.get('#ws-panel-security').isVisible()).toBe(true);
        expect(wrapper.get('#ws-panel-security').text()).toContain('Not set up');
        expect(wrapper.get('a[href="/account/authenticator"]').text()).toBe('Set up authenticator');
        await wrapper.setProps({ security: { enrolled: true, required: true } });
        expect(tab.text()).not.toContain('Action needed');
        expect(wrapper.get('a[href="/account/authenticator"]').text()).toBe('Manage authenticator');
        await wrapper.get('#ws-tab-workspaces').trigger('click');
        expect(window.location.search).toBe('');
    });

    it('opens the security tab and focuses a rejected current password before confirming', async () => {
        const wrapper = screen();
        await wrapper.setProps({ errors: { password: 'The password is incorrect.' } });
        await nextTick();
        expect(wrapper.get('#ws-tab-security').attributes('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(wrapper.get('#revoke_password').element);
        await wrapper.get('#revoke_password').setValue('safe-password-123');
        await wrapper.get('.ws-revoke').trigger('submit');
        expect(wrapper.emitted('revokeSessions')).toBeUndefined();
        expect(confirmation.value?.title).toBe('Sign out all sessions');
        confirmation.value?.action();
        expect(wrapper.emitted('revokeSessions')).toEqual([['safe-password-123']]);
    });
});
