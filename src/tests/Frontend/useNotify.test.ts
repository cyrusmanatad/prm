import { beforeEach, describe, expect, it, vi } from 'vitest';

const toast = {
    success: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
    remove: vi.fn(),
    promise: vi.fn((task: Promise<unknown>, _messages?: unknown, _options?: unknown) => task),
};

vi.mock('vue3-toastify', () => ({ toast }));

const { actionError, flashTone, useNotify } = await import('../../resources/js/composables/useNotify');

beforeEach(() => {
    vi.clearAllMocks();
});

describe('notification copy', () => {
    it('classifies account and session flashes', () => {
        expect(flashTone('If the account exists, a reset link has been requested.')).toBe('info');
        expect(flashTone('Check your email to verify your account')).toBe('info');
        expect(flashTone('Signed out')).toBe('info');
        expect(flashTone('All sessions revoked. Sign in again.')).toBe('warning');
        expect(flashTone('Invitation replaced. Previous sessions are closed')).toBe('warning');
        expect(flashTone('Lead saved')).toBe('success');
        expect(flashTone('Invitation email queued for partner@example.test')).toBe('info');
        expect(
            flashTone(
                'Invitation replaced. Previous sessions are closed. Replacement invitation email queued for partner@example.test',
            ),
        ).toBe('warning');
    });

    it('prefixes action failures and leaves field errors alone', () => {
        expect(actionError('entry', 'This lead has onboarding history.')).toBe(
            "Couldn't delete lead. This lead has onboarding history.",
        );
        expect(actionError('contact', 'Unlink this contact from its leads before deleting.')).toBe(
            "Couldn't delete contact. Unlink this contact from its leads before deleting.",
        );
        expect(actionError('email', 'These credentials do not match our records.')).toBeNull();
    });
});

describe('useNotify', () => {
    it('waits for the pending toast to mount before announcing an immediate clipboard result', async () => {
        const task = Promise.resolve('copied');
        expect(useNotify().promise(task, { pending: 'Copying', success: 'Copied', error: 'Copy failed' })).toBe(task);
        const [feedback, messages] = toast.promise.mock.calls[0]!;
        let settled = false;
        void feedback.then(() => {
            settled = true;
        });
        await Promise.resolve();
        await Promise.resolve();
        expect(settled).toBe(false);
        (messages as { pending: { onOpen: () => void } }).pending.onOpen();
        await expect(feedback).resolves.toBe('copied');
    });

    it('sends plain text with a stable id and keeps errors open longer', () => {
        const notify = useNotify();
        notify.success('Lead saved');
        notify.error("Couldn't save lead. Try again.");
        notify.error("Couldn't copy draft. Select the draft text and copy it manually.", { persist: true });

        expect(toast.success).toHaveBeenCalledWith(
            'Lead saved',
            expect.objectContaining({ toastId: 'success-lead-saved', role: 'status' }),
        );
        expect(toast.error).toHaveBeenNthCalledWith(
            1,
            "Couldn't save lead. Try again.",
            expect.objectContaining({ role: 'alert', autoClose: 6000, toastId: 'error-couldn-t-save-lead-try-again' }),
        );
        notify.dismiss('lead-version');
        expect(toast.remove).toHaveBeenCalledWith('lead-version');
        expect(toast.error).toHaveBeenNthCalledWith(
            2,
            "Couldn't copy draft. Select the draft text and copy it manually.",
            expect.objectContaining({
                role: 'alert',
                autoClose: false,
                toastId: expect.stringContaining('error-couldn-t-copy-draft'),
            }),
        );
    });

    it('wraps a promise without enabling HTML', async () => {
        const notify = useNotify();
        await notify.promise(Promise.resolve('ok'), {
            pending: 'Copying draft',
            success: 'Draft copied',
            error: "Couldn't copy draft. Select the draft text and copy it manually.",
        });
        expect(toast.promise).toHaveBeenCalledWith(
            expect.any(Promise),
            expect.objectContaining({
                pending: expect.objectContaining({
                    render: 'Copying draft',
                    role: 'status',
                    onOpen: expect.any(Function),
                }),
                success: expect.objectContaining({ render: 'Draft copied', role: 'status' }),
                error: expect.objectContaining({ role: 'alert', autoClose: 6000 }),
            }),
            expect.objectContaining({ toastId: expect.stringMatching(/^promise-\d+-copying-draft$/) }),
        );
        expect(JSON.stringify(toast.promise.mock.calls[0])).not.toContain('dangerouslyHTMLString":true');
    });
});

it('uses separate pending notifications for repeated clipboard operations', async () => {
    const notify = useNotify();
    const messages = { pending: 'Copying', success: 'Copied', error: 'Copy failed' };
    notify.promise(Promise.resolve('first'), messages, 'copy');
    notify.promise(Promise.resolve('second'), messages, 'copy');
    const calls = toast.promise.mock.calls;
    expect(calls[0]![2]).not.toEqual(calls[1]![2]);
    for (const [feedback, options] of calls) {
        (options as { pending: { onOpen: () => void } }).pending.onOpen();
        await feedback;
    }
});
