<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { X } from '@lucide/vue';
import type { SelectOption } from './SelectField.vue';

/**
 * Several choices from a searchable list, with the same trigger, panel and option styles as SelectField. Chosen
 * values show as chips that each have their own remove button. The list stays open while choosing; Escape, Done or
 * leaving the field closes it and returns focus to the trigger. `locked` shows the chips read-only with a reason.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        options: SelectOption[];
        placeholder?: string;
        hint?: string;
        error?: string;
        locked?: boolean;
        emptyText?: string;
        searchPlaceholder?: string;
        searchLabel?: string;
        /** Singular noun for counts and chip actions, for example "Partner". */
        noun?: string;
    }>(),
    {
        placeholder: 'Choose…',
        locked: false,
        emptyText: 'No matching options.',
        searchPlaceholder: 'Search',
        noun: 'option',
    },
);
const value = defineModel<string[]>({ default: () => [] });

const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement>();
const trigger = ref<HTMLButtonElement>();
const search = ref<HTMLInputElement>();

const chosen = computed(() =>
    value.value.map((item) => props.options.find((option) => option.value === item) ?? { value: item, label: item }),
);
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return props.options;
    return props.options.filter((option) => `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(needle));
});
const plural = (count: number) => `${count} ${count === 1 ? props.noun : `${props.noun}s`}`;
const countText = computed(() => `${value.value.length} of ${plural(props.options.length)} selected`);
const describedby = computed(
    () =>
        [props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : ''].filter(Boolean).join(' ') ||
        undefined,
);
const triggerId = computed(() => `${props.id}-trigger`);
const listId = computed(() => `${props.id}-listbox`);
const optionId = (optionValue: string) => `${props.id}-option-${optionValue}`;
const activeId = computed(() => {
    const option = filtered.value[active.value];
    return option ? optionId(option.value) : undefined;
});

function toggleValue(option: SelectOption): void {
    value.value = value.value.includes(option.value)
        ? value.value.filter((item) => item !== option.value)
        : [...value.value, option.value];
}
function remove(option: SelectOption): void {
    value.value = value.value.filter((item) => item !== option.value);
    void nextTick(() => trigger.value?.focus());
}
function selectAll(): void {
    value.value = [...new Set([...value.value, ...filtered.value.map((option) => option.value)])];
}
function clear(): void {
    value.value = [];
}
async function show(): Promise<void> {
    if (props.locked || open.value) return;
    open.value = true;
    active.value = 0;
    await nextTick();
    search.value?.focus();
}
function close(returnFocus = true): void {
    if (!open.value) return;
    open.value = false;
    query.value = '';
    if (returnFocus) void nextTick(() => trigger.value?.focus());
}
function highlight(index: number): void {
    active.value = index;
    document.getElementById(optionId(filtered.value[index]?.value ?? ''))?.scrollIntoView({ block: 'nearest' });
}
function onTriggerKey(event: KeyboardEvent): void {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        void show();
    }
}
function onPanelKey(event: KeyboardEvent): void {
    const count = filtered.value.length;
    if (event.key === 'ArrowDown' && count) {
        event.preventDefault();
        highlight((active.value + 1) % count);
    } else if (event.key === 'ArrowUp' && count) {
        event.preventDefault();
        highlight((active.value - 1 + count) % count);
    } else if (event.target === search.value && (event.key === 'Enter' || (event.key === ' ' && !query.value))) {
        event.preventDefault();
        const option = filtered.value[active.value];
        if (option) toggleValue(option);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
}
function onDocumentPointer(event: PointerEvent): void {
    if (open.value && !root.value?.contains(event.target as Node)) close(false);
}
function onFocusOut(event: FocusEvent): void {
    if (open.value && !root.value?.contains(event.relatedTarget as Node | null)) close(false);
}

watch(query, () => (active.value = 0));
onMounted(() => document.addEventListener('pointerdown', onDocumentPointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointer));
</script>

<template>
    <div ref="root" class="wd-field wd-select wd-multiselect" @focusout="onFocusOut">
        <label :id="`${id}-label`" :for="triggerId">{{ label }}</label>
        <!-- The list opens directly under the input box, not under the hint or error below it. -->
        <div class="wd-select-anchor">
            <div
                class="wd-multiselect-control"
                :class="{ 'is-open': open, 'is-locked': locked, 'is-invalid': !!error }"
                @click.self="show"
            >
                <span v-for="option in chosen" :key="option.value" class="wd-multiselect-chip">
                    {{ option.label }}
                    <button
                        v-if="!locked"
                        type="button"
                        class="wd-multiselect-remove"
                        :aria-label="`Remove ${option.label}`"
                        @click="remove(option)"
                    >
                        <X :size="14" aria-hidden="true" />
                    </button>
                </span>
                <button
                    :id="triggerId"
                    ref="trigger"
                    type="button"
                    class="wd-multiselect-trigger"
                    aria-haspopup="listbox"
                    :aria-controls="listId"
                    :aria-expanded="open ? 'true' : 'false'"
                    :aria-labelledby="`${id}-label ${id}-summary`"
                    :aria-describedby="describedby"
                    :aria-invalid="error ? 'true' : undefined"
                    :disabled="locked"
                    @click="open ? close() : show()"
                    @keydown="onTriggerKey"
                >
                    <span v-if="!chosen.length" class="wd-multiselect-placeholder">{{ placeholder }}</span>
                    <span v-if="!locked" class="wd-select-chevron" aria-hidden="true"></span>
                </button>
                <span :id="`${id}-summary`" class="wd-sr-only">{{
                    chosen.length ? chosen.map((option) => option.label).join(', ') : placeholder
                }}</span>
            </div>
            <div v-if="open" class="wd-select-panel wd-multiselect-panel" @keydown="onPanelKey">
                <div class="wd-select-search-wrap">
                    <svg
                        class="wd-select-search-icon"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-3.5-3.5" />
                    </svg>
                    <input
                        ref="search"
                        v-model="query"
                        class="wd-select-search has-icon"
                        type="search"
                        role="combobox"
                        :aria-label="searchLabel || `Search ${label}`"
                        :aria-controls="listId"
                        aria-expanded="true"
                        aria-autocomplete="list"
                        :aria-activedescendant="activeId"
                        autocomplete="off"
                        :placeholder="searchPlaceholder"
                    />
                </div>
                <div class="wd-multiselect-bulk">
                    <span aria-live="polite">{{ countText }}</span>
                    <span>
                        <button type="button" class="wd-multiselect-action" @click="selectAll">Select all</button>
                        ·
                        <button type="button" class="wd-multiselect-action" @click="clear">Clear</button>
                    </span>
                </div>
                <ul
                    :id="listId"
                    class="wd-select-options"
                    role="listbox"
                    aria-multiselectable="true"
                    :aria-labelledby="`${id}-label`"
                >
                    <li v-if="!filtered.length" class="wd-select-empty" role="status">{{ emptyText }}</li>
                    <li
                        v-for="(option, index) in filtered"
                        :id="optionId(option.value)"
                        :key="option.value"
                        class="wd-select-option wd-multiselect-option"
                        :class="{ 'is-active': index === active }"
                        role="option"
                        :aria-selected="value.includes(option.value) ? 'true' : 'false'"
                        @mousedown.prevent
                        @mouseenter="active = index"
                        @click="toggleValue(option)"
                    >
                        <span class="wd-multiselect-box" aria-hidden="true">
                            <svg
                                width="12"
                                height="12"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="3.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M5 12l5 5 9-10" />
                            </svg>
                        </span>
                        <span>{{ option.label }}</span>
                        <span v-if="option.hint" class="wd-multiselect-hint">{{ option.hint }}</span>
                    </li>
                </ul>
                <div class="wd-multiselect-foot">
                    <button type="button" class="wd-button wd-button-secondary" @click="close()">Done</button>
                </div>
            </div>
        </div>
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
