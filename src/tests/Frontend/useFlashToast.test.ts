import { beforeEach, expect, it, vi } from 'vitest';

const { handlers, notify } = vi.hoisted(() => ({
    handlers: {} as Record<string, (event: any) => unknown>,
    notify: { info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn(), dismiss: vi.fn() },
}));
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: vi.fn((name: string, callback: (event: any) => unknown) => {
            handlers[name] = callback;
        }),
    },
}));
vi.mock('../../resources/js/composables/useNotify', async (original) => ({
    ...(await original<object>()),
    useNotify: () => notify,
}));

beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
});

it('announces initial flash once and repeats successful actions without replaying history', async () => {
    const { registerFlashToasts } = await import('../../resources/js/composables/useFlashToast');
    registerFlashToasts({ status: 'Lead saved' });
    registerFlashToasts({ status: 'Lead saved' });
    handlers.navigate!({ detail: { type: 'initial', page: { props: { status: 'Lead saved' } } } });
    expect(notify.success).toHaveBeenCalledTimes(1);
    handlers.navigate!({ detail: { type: 'history', page: { props: { status: 'Lead saved' } } } });
    expect(notify.success).toHaveBeenCalledTimes(1);
    handlers.success!({ detail: { page: { props: { status: 'Lead saved' } } } });
    handlers.success!({ detail: { page: { props: { status: 'Lead saved' } } } });
    expect(notify.success).toHaveBeenCalledTimes(3);
    expect(new Set(notify.success.mock.calls.map((call) => call[1])).size).toBe(3);
    handlers.success!({ detail: { page: { props: { status: '' } } } });
    expect(notify.success).toHaveBeenCalledTimes(3);
});

it('retains action/network guidance, leaves field errors inline and clears recovered network feedback', async () => {
    const { registerFlashToasts } = await import('../../resources/js/composables/useFlashToast');
    registerFlashToasts();
    handlers.error!({ detail: { errors: { email: 'Invalid email', entry: ['This lead has onboarding history.'] } } });
    expect(notify.error).toHaveBeenCalledTimes(1);
    expect(notify.error).toHaveBeenCalledWith("Couldn't delete lead. This lead has onboarding history.", {
        toastId: 'action:entry',
        persist: true,
    });
    expect(handlers.networkError!({})).toBe(false);
    expect(notify.error).toHaveBeenLastCalledWith(expect.stringContaining('Check the current record'), {
        toastId: 'network',
        persist: true,
    });
    handlers.success!({ detail: { page: { props: {} } } });
    expect(notify.dismiss).toHaveBeenCalledWith('network');
});
