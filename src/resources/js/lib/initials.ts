/** Up to two capital initials from a display name, for avatars. */
export function initials(name: string, fallback = '?'): string {
    const letters = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
    return letters || fallback;
}

/** A stable choice from `count` options for an identifier, so a record keeps the same colour everywhere. */
export function pick(seed: string, count: number): number {
    let hash = 0;
    for (const char of seed) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    return hash % count;
}
