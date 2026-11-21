import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Sends a finance command with an idempotency key. The same request (path and data) reuses its key, so a
 * retried submission after a lost response cannot record twice; a successful one forgets it.
 */
export function useFinanceCommand() {
    const busy = ref(false);
    const keys = new Map<string, string>();
    function send(
        path: string,
        data: Record<string, unknown>,
        options: { method?: 'post' | 'patch'; onSuccess?: () => void } = {},
    ): void {
        if (busy.value) return;
        const identity = path + JSON.stringify(data);
        const key = keys.get(identity) ?? crypto.randomUUID();
        keys.set(identity, key);
        router.visit(path, {
            method: options.method ?? 'post',
            data: { ...data, command_id: key },
            preserveScroll: true,
            // Keeps open dialogs, filters and the row-menu trigger, so focus can return to it after a change.
            preserveState: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            onSuccess: () => {
                keys.delete(identity);
                options.onSuccess?.();
            },
        });
    }
    return { busy, send };
}
