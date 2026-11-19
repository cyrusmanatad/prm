import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Filter state for a server-paged list. The controls edit a local draft; `applied` always reflects
 * what the server returned, so a pending or unsent edit is never presented as an applied filter.
 * Applying filters returns to the first page, and Back/Forward restores the draft from server props.
 * `apply` sends a change at once for controls that filter as they are chosen; it keeps component
 * state so a focused control (for example a search field being typed in) is not remounted.
 */
export function useListFilters<Filters extends Record<string, string>>(path: string, applied: () => Filters) {
    const draft = reactive({ ...applied() }) as Filters;
    const pending = ref(false);
    watch(applied, (value) => Object.assign(draft, value), { deep: true });
    function visit(filters: Partial<Filters>, preserveState = false) {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
        router.get(path, query, {
            preserveScroll: true,
            preserveState,
            onStart: () => (pending.value = true),
            onFinish: () => (pending.value = false),
        });
    }
    function apply(change: Partial<Filters>) {
        Object.assign(draft, change);
        visit({ ...applied(), ...change }, true);
    }
    /** Address of a page of the applied list; `change` swaps applied filters, such as the sort order. */
    function href(page: number, change: Partial<Filters> = {}): string {
        const search = new URLSearchParams();
        for (const [key, value] of Object.entries({ ...applied(), ...change })) if (value) search.set(key, value);
        if (page > 1) search.set('page', String(page));
        const text = search.toString();
        return text ? `${path}?${text}` : path;
    }
    return { draft, pending, submit: () => visit(draft), apply, clear: () => visit({}), href };
}
