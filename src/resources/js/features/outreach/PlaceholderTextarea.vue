<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { mergeFields } from './merge';

/**
 * A plain-text textarea that marks {{placeholders}}. The text stays in a real textarea, so typing,
 * pasting, undo and assistive technology behave as usual; a mirror behind it only paints highlights.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        maxlength?: number;
        rows?: number;
        required?: boolean;
        error?: string;
        describedby?: string;
    }>(),
    { maxlength: 5000, rows: 8, required: false },
);
const value = defineModel<string>({ default: '' });
const textarea = ref<HTMLTextAreaElement>();
const mirror = ref<HTMLElement>();
const countId = computed(() => `${props.id}-count`);
const describedby = computed(() =>
    [countId.value, props.describedby, props.error ? `${props.id}-error` : ''].filter(Boolean).join(' '),
);
const parts = computed(() =>
    value.value.split(/(\{\{[^{}]*\}\})/g).map((text) => {
        const name = /^\{\{([^{}]*)\}\}$/.exec(text)?.[1]?.trim();
        return {
            text,
            mark: name === undefined ? '' : (mergeFields as readonly string[]).includes(name) ? 'known' : 'unknown',
        };
    }),
);
function sync(): void {
    if (mirror.value && textarea.value) mirror.value.scrollTop = textarea.value.scrollTop;
}
/** Inserts text at the caret (replacing any selection) and keeps the caret after it. */
async function insert(text: string): Promise<void> {
    const field = textarea.value;
    if (!field) return;
    const start = field.selectionStart ?? value.value.length;
    const end = field.selectionEnd ?? start;
    value.value = value.value.slice(0, start) + text + value.value.slice(end);
    await nextTick();
    field.focus();
    field.setSelectionRange(start + text.length, start + text.length);
    sync();
}
defineExpose({ insert, focus: () => textarea.value?.focus() });
</script>

<template>
    <div class="wd-field wd-placeholder-field">
        <div class="wd-placeholder-label">
            <label :for="id">{{ label }}</label>
            <span :id="countId" class="wd-placeholder-count"
                >{{ value.length.toLocaleString() }} / {{ maxlength.toLocaleString()
                }}<span class="wd-sr-only"> characters</span></span
            >
        </div>
        <div class="wd-placeholder-box" :class="{ 'is-invalid': error }">
            <slot name="tools" />
            <div class="wd-placeholder-area">
                <div ref="mirror" class="wd-placeholder-mirror" aria-hidden="true">
                    <template v-for="(part, index) in parts" :key="index"
                        ><mark v-if="part.mark" :class="`is-${part.mark}`">{{ part.text }}</mark
                        ><template v-else>{{ part.text }}</template></template
                    >{{ '\n' }}
                </div>
                <textarea
                    :id="id"
                    ref="textarea"
                    v-model="value"
                    :rows="rows"
                    :maxlength="maxlength"
                    :required="required"
                    spellcheck="true"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="describedby"
                    @scroll="sync"
                    @input="sync"
                />
            </div>
        </div>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.wd-placeholder-label {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}
.wd-placeholder-count {
    color: var(--wd-muted);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
}
.wd-placeholder-box {
    border: 1px solid var(--wd-control-line);
    border-radius: 10px;
    background: var(--wd-surface);
    overflow: hidden;
}
.wd-placeholder-box:focus-within {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.wd-placeholder-box.is-invalid {
    border-color: var(--wd-danger-border);
}
.wd-placeholder-area {
    position: relative;
}
/* The mirror and the textarea share every metric so highlights sit exactly under the text. */
.wd-placeholder-mirror,
.wd-placeholder-area textarea {
    margin: 0;
    padding: 10px 12px;
    border: 0;
    font: inherit;
    font-size: 14px;
    line-height: 1.6;
    letter-spacing: normal;
    white-space: pre-wrap;
    overflow-wrap: break-word;
    word-break: normal;
    tab-size: 4;
    scrollbar-gutter: stable;
}
.wd-placeholder-mirror {
    position: absolute;
    inset: 0;
    overflow: hidden;
    color: transparent;
    pointer-events: none;
}
.wd-placeholder-mirror mark {
    border-radius: 4px;
    color: transparent;
}
.wd-placeholder-mirror mark.is-known {
    background: var(--color-blush);
}
.wd-placeholder-mirror mark.is-unknown {
    background: var(--wd-warning-bg);
    box-shadow: inset 0 -2px 0 var(--wd-warning);
}
.wd-placeholder-area textarea {
    position: relative;
    display: block;
    width: 100%;
    min-height: 0;
    resize: vertical;
    border-radius: 0;
    background: transparent;
    color: var(--wd-ink);
    outline: none;
}
.wd-placeholder-area textarea:focus-visible {
    outline: none;
}
</style>
