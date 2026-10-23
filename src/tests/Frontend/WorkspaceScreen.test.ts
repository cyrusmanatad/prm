import { confirmation } from '../../resources/js/composables/useConfirmation';
import { afterEach, describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import WorkspaceScreen from '../../resources/js/features/workspaces/WorkspaceScreen.vue';
import type { WorkspaceSummary } from '../../resources/js/types/foundation';

const workspaces: WorkspaceSummary[] = [
    { id: 'workspace-a', name: 'Creator Studio', role: 'owner' },
    { id: 'workspace-b', name: 'Shared Studio', role: 'viewer' },
];
const wrappers: VueWrapper[] = [];
function screen(spaces = workspaces) {
    const wrapper = mount(WorkspaceScreen, {
        props: {
            account: { name: 'Creator', email: 'creator@example.test' },
            workspaces: spaces,
            activeOrgId: spaces[0]?.id,
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    confirmation.value = null;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('workspace screens', () => {
    it('explains the empty state and submits a first workspace name', async () => {
        const wrapper = screen([]);
        expect(wrapper.text()).toContain('You don’t have an active workspace yet');
        await wrapper.get('#workspace_name').setValue('  My Studio  ');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('create')).toEqual([['My Studio', 'creator']]);
    });

    it('selects by workspace ID and exposes which workspace is selected', async () => {
        const wrapper = screen();
        const buttons = wrapper.findAll('.wd-workspace-option');
        expect(buttons[0]?.attributes('aria-pressed')).toBe('true');
        await buttons[1]?.trigger('click');
        expect(wrapper.emitted('select')).toEqual([['workspace-b']]);
    });

    it('allows owner settings but removes the form after switching to a viewer workspace', async () => {
        const wrapper = screen();
        await wrapper.get('#current_workspace_name').setValue('Updated Studio');
        await wrapper.get('.wd-settings-card form').trigger('submit');
        expect(wrapper.emitted('rename')).toEqual([['Updated Studio']]);
        await wrapper.setProps({ activeOrgId: 'workspace-b' });
        expect(wrapper.find('#current_workspace_name').exists()).toBe(false);
        expect(wrapper.get('.wd-settings-card').text()).toContain('viewer role does not include');
    });

    it('hides revoked workspace settings and offers a new selection', async () => {
        const wrapper = screen();
        await wrapper.setProps({ accessRevoked: true });
        expect(wrapper.get('[role="alert"]').text()).toContain('access to the previous workspace has changed');
        expect(wrapper.find('.wd-settings-card').exists()).toBe(false);
        expect(wrapper.findAll('.wd-workspace-option[aria-pressed="true"]')).toHaveLength(0);
    });

    it('prevents overlapping workspace mutations', async () => {
        const wrapper = screen();
        await wrapper.setProps({ pendingAction: 'create' });
        await wrapper.get('#workspace_name').setValue('Another studio');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('.wd-settings-card form').trigger('submit');
        expect(wrapper.emitted('create')).toBeUndefined();
        expect(wrapper.emitted('rename')).toBeUndefined();
        expect(wrapper.get('.wd-workspace-option').attributes()).toHaveProperty('disabled');
    });

    it('opens security settings and focuses a rejected current password', async () => {
        const wrapper = screen();
        await wrapper.setProps({ errors: { password: 'The password is incorrect.' } });
        expect((wrapper.get('details').element as HTMLDetailsElement).open).toBe(true);
        expect(document.activeElement).toBe(wrapper.get('#revoke_password').element);
        await wrapper.get('#revoke_password').setValue('safe-password-123');
        await wrapper.get('.wd-security-card form').trigger('submit');
        expect(wrapper.emitted('revokeSessions')).toBeUndefined();
        expect(confirmation.value?.title).toBe('Sign out all sessions');
        confirmation.value?.action();
        expect(wrapper.emitted('revokeSessions')).toEqual([['safe-password-123']]);
    });
});
