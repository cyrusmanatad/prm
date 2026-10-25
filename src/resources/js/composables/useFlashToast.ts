import { router } from '@inertiajs/vue3';
import { actionError, flashTone, useNotify } from './useNotify';

const fallback = 'Something went wrong. Please try again.';
let registered = false;

function statusText(props: object): string {
    const status = (props as { status?: unknown }).status;
    return typeof status === 'string' ? status.trim() : '';
}

function errorText(value: unknown): string {
    if (typeof value === 'string') return value.trim();
    if (Array.isArray(value) && typeof value[0] === 'string') return value[0].trim();
    return '';
}

export function registerFlashToasts(initialProps?: object): void {
    const notify = useNotify();
    const showStatus = (props: object): void => {
        const message = statusText(props);
        if (!message) return;
        const tone = flashTone(message);
        const toastId = `flash-${message}`;
        if (tone === 'info') notify.info(message, toastId);
        else if (tone === 'warning') notify.warning(message, toastId);
        else notify.success(message, toastId);
    };

    if (registered) return;
    registered = true;

    let toastedInitial = false;
    const showInitial = (props: object): void => {
        if (toastedInitial) return;
        toastedInitial = true;
        showStatus(props);
    };

    router.on('navigate', (event) => {
        if (event.detail.type === 'initial') showInitial(event.detail.page.props);
    });
    router.on('success', (event) => {
        showStatus(event.detail.page.props);
    });
    router.on('error', (event) => {
        for (const [key, value] of Object.entries(event.detail.errors)) {
            const message = actionError(key, errorText(value));
            if (message) notify.error(message, { toastId: `action:${key}` });
        }
    });
    router.on('networkError', () => {
        notify.error(fallback, { toastId: 'network' });
    });

    if (initialProps) showInitial(initialProps);
}
