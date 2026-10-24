export function containDialogFocus(event: KeyboardEvent, root?: HTMLElement | null): void {
    if (event.key !== 'Tab' || !root) return;
    const controls = [
        ...root.querySelectorAll<HTMLElement>(
            'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
        ),
    ].filter((node) => !node.hasAttribute('disabled') && node.tabIndex !== -1);
    const first = controls[0];
    const last = controls.at(-1);
    if (
        !first ||
        (event.shiftKey && document.activeElement === first) ||
        (!event.shiftKey && document.activeElement === last)
    ) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
}
