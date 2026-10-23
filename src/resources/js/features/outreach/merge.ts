export const mergeFields = ['brand_name', 'contact_name', 'creator_name'] as const;
export type MergeValues = Record<typeof mergeFields[number], string>;
// One pass: inserted values are never evaluated as template syntax or HTML.
export function mergeText(source: string, values: MergeValues): { text: string; unresolved: string[] } {
    const unresolved = new Set<string>();
    const text = source.replace(/\{\{([^{}]*)\}\}/g, (token: string, key: string) => {
        const name = key.trim();
        if (mergeFields.includes(name as typeof mergeFields[number])) {
            const value = values[name as keyof MergeValues];
            if (value.trim()) return value;
        }
        unresolved.add(name);
        return token;
    });
    if (/\{\{|\}\}/.test(source.replace(/\{\{[^{}]*\}\}/g, ''))) unresolved.add('malformed placeholder');
    return { text, unresolved: [...unresolved] };
}
