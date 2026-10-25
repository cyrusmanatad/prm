export function containDialogFocus(event: KeyboardEvent, root?: HTMLElement | null): void {
    if (event.key !== 'Tab' || !root) return;
    const controls = [
        ...root.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, summary, [tabindex]'),
    ].filter((node) => {
        if (node.matches(':disabled') || node.tabIndex < 0 || node.closest('[inert], [hidden]')) return false;
        for (let parent: HTMLElement | null = node; parent; parent = parent.parentElement) {
            const style = getComputedStyle(parent);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            if (parent instanceof HTMLDetailsElement && !parent.open) {
                const summary = parent.querySelector(':scope > summary');
                if (!summary?.contains(node)) return false;
            }
            if (parent === root) break;
        }
        return true;
    });
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
