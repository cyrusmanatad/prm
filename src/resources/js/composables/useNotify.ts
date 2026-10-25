import { toast, type Id, type ToastOptions } from 'vue3-toastify';

const reducedMotion =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const base: ToastOptions = {
    clearOnUrlChange: false,
    onOpen: liftToastContainer,
    ...(reducedMotion ? { transition: 'none' as const } : {}),
};

function stableId(value: Id): Id {
    return String(value)
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 80);
}

function toastIdFor(kind: string, message: string): Id {
    return stableId(`${kind}:${message}`);
}

function announce(kind: 'success' | 'info' | 'warning' | 'error', message: string, options: ToastOptions): void {
    const id = stableId(options.toastId ?? toastIdFor(kind, message));
    toast[kind](message, { ...base, ...options, toastId: id });
    liftToastContainer();
}

let layerObserver: MutationObserver | undefined;
let layeredContainer: HTMLElement | null = null;

function liftToastContainer(): void {
    if (typeof document === 'undefined' || typeof requestAnimationFrame !== 'function') return;
    if (!layerObserver && typeof MutationObserver !== 'undefined') {
        layerObserver = new MutationObserver(() => liftToastContainer());
        layerObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['open'],
        });
    }
    requestAnimationFrame(() => {
        const container =
            document.querySelector<HTMLElement>('.Toastify__toast-container') ??
            (layeredContainer?.parentElement?.tagName === 'DIALOG' ? layeredContainer : null);
        if (!container) return;
        layeredContainer = container;
        // A top-layer popover outside a modal is still inert. Keep feedback inside
        // the active dialog so its announcements and close button remain usable.
        const dialogs = document.querySelectorAll<HTMLDialogElement>('dialog:modal');
        const parent = dialogs.item(dialogs.length - 1) ?? document.body;
        if (container.parentElement !== parent) parent.append(container);
        container.setAttribute('role', 'region');
        container.setAttribute('aria-label', 'Notifications');
        if (typeof container.showPopover !== 'function') return;
        container.setAttribute('popover', 'manual');
        try {
            // Reopening on every DOM update cancels Toastify's lifecycle animations.
            if (!container.matches(':popover-open')) container.showPopover();
        } catch {
            // The popover API is unavailable while the container is mid-transition.
        }
    });
}

export function flashTone(message: string): 'success' | 'info' | 'warning' {
    if (message.startsWith('All sessions revoked') || message.includes('Previous sessions are closed')) {
        return 'warning';
    }
    if (
        message.startsWith('If the account exists') ||
        message.startsWith('Check your email') ||
        message === 'Signed out' ||
        message === 'Invitation opened' ||
        message.includes('email queued for')
    ) {
        return 'info';
    }
    return 'success';
}

const actionLabels: Record<string, string> = {
    entry: 'delete lead',
    contact: 'delete contact',
    general: 'complete that action',
};

export function actionError(key: string, reason: string): string | null {
    const action = actionLabels[key];
    if (!action) return null;
    const detail = reason.replace(/\s+/g, ' ').trim().replace(/\.$/, '');
    return detail ? `Couldn't ${action}. ${detail}.` : `Couldn't ${action}.`;
}

let promiseSequence = 0;

export function useNotify() {
    return {
        success(message: string, toastId?: Id): void {
            announce('success', message, { toastId, role: 'status' });
        },
        info(message: string, toastId?: Id): void {
            announce('info', message, { toastId, role: 'status' });
        },
        warning(message: string, toastId?: Id): void {
            announce('warning', message, { toastId, role: 'alert', autoClose: 6000 });
        },
        error(message: string, options?: { persist?: boolean; toastId?: Id }): void {
            announce('error', message, {
                toastId: options?.toastId,
                role: 'alert',
                autoClose: options?.persist ? false : 6000,
            });
        },
        dismiss(toastId: Id): void {
            toast.remove(stableId(toastId));
        },
        promise<T>(
            task: Promise<T>,
            messages: { pending: string; success: string; error: string },
            toastId?: Id,
        ): Promise<T> {
            const id = stableId(`promise-${++promiseSequence}-${toastId ?? messages.pending}`);
            let opened!: () => void;
            const mounted = new Promise<void>((resolve) => {
                opened = resolve;
            });
            // The library adds loading toasts asynchronously. Wait for onOpen so
            // an immediately resolved clipboard write cannot update a missing toast.
            const feedback = task.then(
                (value) => mounted.then(() => value),
                (error: unknown) =>
                    mounted.then(() => {
                        throw error;
                    }),
            );
            toast.promise(
                feedback,
                {
                    pending: {
                        render: messages.pending,
                        role: 'status',
                        onOpen: () => {
                            liftToastContainer();
                            opened();
                        },
                    },
                    success: { render: messages.success, type: 'success', role: 'status', autoClose: 3500 },
                    error: { render: messages.error, type: 'error', role: 'alert', autoClose: 6000 },
                },
                { ...base, toastId: id },
            );
            liftToastContainer();
            return task;
        },
    };
}
