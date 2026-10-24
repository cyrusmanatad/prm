import { shallowRef } from 'vue';

export type ConfirmationTone = 'default' | 'danger';
export type Confirmation = {
    title: string;
    description: string;
    action: () => void;
    trigger: HTMLElement | null;
    tone: ConfirmationTone;
};
export const confirmation = shallowRef<Confirmation | null>(null);

/** Request confirmation before invoking an existing mutation handler. Opening never sends a request. */
export function confirmAction(
    title: string,
    description: string,
    action: () => void,
    source?: Event | HTMLElement,
    options?: { tone?: ConfirmationTone },
): void {
    if (confirmation.value) return;
    const trigger =
        source instanceof HTMLElement
            ? source
            : source instanceof SubmitEvent
              ? source.submitter
              : source?.currentTarget;
    confirmation.value = {
        title,
        description,
        action,
        tone: options?.tone ?? 'default',
        trigger:
            trigger instanceof HTMLElement
                ? trigger
                : document.activeElement instanceof HTMLElement
                  ? document.activeElement
                  : null,
    };
}
