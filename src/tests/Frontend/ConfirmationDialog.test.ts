import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils';
import ConfirmationDialog from '../../resources/js/components/ConfirmationDialog.vue';
import { confirmation, confirmAction } from '../../resources/js/composables/useConfirmation';

const { listeners } = vi.hoisted(() => ({ listeners: new Map<string, (event?: unknown) => void>() }));
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, callback: (event?: unknown) => void) => {
            listeners.set(name, callback);
            return () => listeners.delete(name);
        },
    },
}));
let wrapper: VueWrapper;
let trigger: HTMLButtonElement;
beforeEach(() => {
    HTMLDialogElement.prototype.showModal = function () {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function () {
        this.open = false;
    };
    trigger = document.createElement('button');
    document.body.append(trigger);
    trigger.focus();
    wrapper = mount(ConfirmationDialog, { attachTo: document.body });
});
afterEach(() => {
    wrapper.unmount();
    confirmation.value = null;
    document.body.innerHTML = '';
});
async function open(action = vi.fn()) {
    confirmAction('Delete lead', 'Permanently delete Acme and its history.', action);
    await flushPromises();
    return action;
}
function button() {
    return document.querySelector<HTMLButtonElement>('button[type="submit"]')!;
}
async function submit() {
    document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
}
describe('confirmation lifecycle', () => {
    it('opens without mutation, focuses Cancel and restores the trigger on cancellation', async () => {
        const action = await open();
        expect(action).not.toHaveBeenCalled();
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');
        (document.activeElement as HTMLButtonElement).click();
        await flushPromises();
        expect(document.querySelector('dialog')?.open).toBe(false);
        expect(document.activeElement).toBe(trigger);
        expect(action).not.toHaveBeenCalled();
    });
    it('cancels with Escape without invoking the mutation', async () => {
        const action = await open();
        document.querySelector('dialog')!.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();
        expect(action).not.toHaveBeenCalled();
        expect(confirmation.value).toBeNull();
    });
    it('prevents duplicate requests and closes after success', async () => {
        const action = vi.fn(() => listeners.get('start')?.());
        await open(action);
        await submit();
        await submit();
        expect(action).toHaveBeenCalledTimes(1);
        expect(button().disabled).toBe(true);
        listeners.get('success')?.();
        await flushPromises();
        expect(confirmation.value).toBeNull();
        expect(document.activeElement).toBe(trigger);
    });
    it('retains accessible server errors and blocks retrying stale versions', async () => {
        const action = vi.fn(() => listeners.get('start')?.());
        await open(action);
        await submit();
        listeners.get('error')?.({ detail: { errors: { version: 'Reload latest record.' } } });
        listeners.get('finish')?.();
        await flushPromises();
        const alert = document.querySelector('[role="alert"]');
        expect(alert?.textContent).toContain('Reload latest record.');
        expect(document.activeElement).toBe(alert);
        expect(button().disabled).toBe(true);
        await submit();
        expect(action).toHaveBeenCalledTimes(1);
    });
    it('contains Tab focus within the dialog', async () => {
        await open();
        button().focus();
        button().dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');
    });
});
