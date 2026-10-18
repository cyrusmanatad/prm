import { onMounted, watch, type Ref } from 'vue';
import type { FieldErrors } from '../types/foundation';

/** Move focus after Vue has rendered validation errors, including closed disclosures. */
export function useValidationFocus(root: Ref<HTMLElement | undefined>, errors: () => FieldErrors, enabled: () => boolean = () => true): void {
    function focus(): void {
        if (!enabled() || !Object.keys(errors()).length) return;
        const input = root.value?.querySelector<HTMLInputElement>('[aria-invalid="true"]');
        const details = input?.closest('details');
        if (details) details.open = true;
        input?.focus();
    }
    watch([errors, enabled], focus, { flush: 'post' });
    onMounted(focus);
}
