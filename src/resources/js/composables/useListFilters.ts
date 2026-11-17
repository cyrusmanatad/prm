import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Filter state for a server-paged list. The controls edit a local draft; `applied` always reflects
 * what the server returned, so a pending or unsent edit is never presented as an applied filter.
 * Applying filters returns to the first page, and Back/Forward restores the draft from server props.
 */
export function useListFilters<Filters extends Record<string, string>>(path: string, applied: () => Filters) {
    const draft = reactive({ ...applied() }) as Filters;
    const pending = ref(false);
    watch(applied, (value) => Object.assign(draft, value), { deep: true });
    function visit(filters: Partial<Filters>) {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
        router.get(path, query, {
            preserveScroll: true,
            onStart: () => (pending.value = true),
            onFinish: () => (pending.value = false),
        });
    }
    function href(page: number): string {
        const search = new URLSearchParams();
        for (const [key, value] of Object.entries(applied())) if (value) search.set(key, value);
        if (page > 1) search.set('page', String(page));
        const text = search.toString();
        return text ? `${path}?${text}` : path;
    }
    return { draft, pending, submit: () => visit(draft), clear: () => visit({}), href };
}
