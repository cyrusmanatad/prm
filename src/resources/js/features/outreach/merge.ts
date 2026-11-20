export const mergeFields = ['brand_name', 'contact_name', 'creator_name'] as const;
export type MergeValues = Record<(typeof mergeFields)[number], string>;
// One pass: inserted values are never evaluated as template syntax or HTML.
export function mergeText(source: string, values: MergeValues): { text: string; unresolved: string[] } {
    const unresolved = new Set<string>();
    const text = source.replace(/\{\{([^{}]*)\}\}/g, (token: string, key: string) => {
        const name = key.trim();
        if (mergeFields.includes(name as (typeof mergeFields)[number])) {
            const value = values[name as keyof MergeValues];
            if (value.trim()) return value;
        }
        unresolved.add(name);
        return token;
    });
    if (/\{\{|\}\}/.test(source.replace(/\{\{[^{}]*\}\}/g, ''))) unresolved.add('malformed placeholder');
    return { text, unresolved: [...unresolved] };
}

export type MergeSegment = { text: string; kind: 'text' | 'filled' | 'missing' };
export const mergeLabels: Record<(typeof mergeFields)[number], string> = {
    brand_name: 'brand name',
    contact_name: 'contact name',
    creator_name: 'creator name',
};
/** The merged text split into plain, filled and missing parts, for a preview that marks each placeholder. */
export function mergeSegments(source: string, values: MergeValues): MergeSegment[] {
    const segments: MergeSegment[] = [];
    let last = 0;
    for (const match of source.matchAll(/\{\{([^{}]*)\}\}/g)) {
        if (match.index > last) segments.push({ text: source.slice(last, match.index), kind: 'text' });
        const name = match[1]!.trim() as (typeof mergeFields)[number];
        const value = mergeFields.includes(name) ? values[name].trim() : '';
        segments.push(
            value ? { text: values[name], kind: 'filled' } : { text: mergeLabels[name] ?? match[0], kind: 'missing' },
        );
        last = match.index + match[0].length;
    }
    if (last < source.length) segments.push({ text: source.slice(last), kind: 'text' });
    return segments;
}
