<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type SelectOption = { value: string; label: string; hint?: string };

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        options: SelectOption[];
        hint?: string;
        error?: string;
        required?: boolean;
        searchable?: boolean;
        disabled?: boolean;
        emptyText?: string;
        searchPlaceholder?: string;
        searchLabel?: string;
        showMark?: boolean;
        showSearchIcon?: boolean;
    }>(),
    {
        required: false,
        searchable: true,
        disabled: false,
        emptyText: 'No matching options.',
        searchPlaceholder: 'Search',
        showMark: false,
        showSearchIcon: false,
    },
);

const value = defineModel<string>({ default: '' });
const emit = defineEmits<{ change: [value: string] }>();

const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement>();
const trigger = ref<HTMLButtonElement>();
const search = ref<HTMLInputElement>();
const list = ref<HTMLElement>();

const selected = computed(() => props.options.find((option) => option.value === value.value));
const searchAria = computed(() => props.searchLabel || `Search ${props.label}`);
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return props.options;
    return props.options.filter((option) => `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(needle));
});
const describedby = computed(
    () =>
        [props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : ''].filter(Boolean).join(' ') ||
        undefined,
);
const triggerId = computed(() => `${props.id}-trigger`);
const listId = computed(() => `${props.id}-listbox`);
const searchId = computed(() => `${props.id}-search`);
const activeId = computed(() => {
    const option = filtered.value[active.value];
    return option ? optionId(option.value) : undefined;
});

function optionId(optionValue: string): string {
    return `${props.id}-option-${optionValue || 'empty'}`;
}
function setValue(next: string): void {
    if (value.value === next) return;
    value.value = next;
    emit('change', next);
}
function syncNative(event: Event): void {
    setValue((event.target as HTMLSelectElement).value);
}
function highlightSelected(): void {
    // A typed query commits the first match. An empty query keeps the current selection highlighted.
    if (query.value.trim()) {
        active.value = 0;
        return;
    }
    const index = filtered.value.findIndex((option) => option.value === value.value);
    active.value = index >= 0 ? index : 0;
}
function close(): void {
    if (!open.value) return;
    open.value = false;
    query.value = '';
    nextTick(() => trigger.value?.focus());
}
async function show(): Promise<void> {
    if (props.disabled || open.value) return;
    open.value = true;
    highlightSelected();
    await nextTick();
    (props.searchable ? search.value : list.value?.querySelector<HTMLElement>('[role="option"]'))?.focus();
}
function toggle(): void {
    if (open.value) close();
    else void show();
}
function choose(option: SelectOption): void {
    setValue(option.value);
    close();
}
function highlight(index: number): void {
    active.value = index;
    const option = document.getElementById(optionId(filtered.value[active.value]?.value ?? ''));
    option?.scrollIntoView({ block: 'nearest' });
    if (document.activeElement?.getAttribute('role') === 'option') option?.focus();
}
function move(delta: number): void {
    if (!filtered.value.length) return;
    const count = filtered.value.length;
    highlight((active.value + delta + count) % count);
}
function onTriggerKey(event: KeyboardEvent): void {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        void show();
    }
}
function onPanelKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Home' && event.target !== search.value) {
        event.preventDefault();
        highlight(0);
    } else if (event.key === 'End' && event.target !== search.value) {
        event.preventDefault();
        highlight(Math.max(filtered.value.length - 1, 0));
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const focused = event.target instanceof HTMLElement ? event.target.id : '';
        const option = filtered.value.find((item) => optionId(item.value) === focused) ?? filtered.value[active.value];
        if (option) choose(option);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
}
function onDocumentPointer(event: PointerEvent): void {
    if (!open.value || root.value?.contains(event.target as Node)) return;
    close();
}

watch([query, () => props.options], highlightSelected);
onMounted(() => document.addEventListener('pointerdown', onDocumentPointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointer));
</script>

<template>
    <div ref="root" class="wd-field wd-select">
        <label :id="`${id}-label`" :for="triggerId">{{ label }}</label>
        <select
            :id="id"
            class="wd-select-native"
            :name="id"
            :value="value"
            :required="required"
            :disabled="disabled"
            :aria-hidden="true"
            tabindex="-1"
            @change="syncNative"
        >
            <option v-for="option in options" :key="`${id}-${option.value}`" :value="option.value">
                {{ option.label }}
            </option>
        </select>
        <button
            :id="triggerId"
            ref="trigger"
            type="button"
            class="wd-select-trigger"
            role="combobox"
            :aria-labelledby="`${id}-label`"
            :aria-controls="listId"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-autocomplete="searchable ? 'list' : 'none'"
            :aria-activedescendant="open ? activeId : undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedby"
            :aria-required="required ? 'true' : undefined"
            :disabled="disabled"
            @click="toggle"
            @keydown="onTriggerKey"
        >
            <span>{{ selected?.label || 'Choose an option' }}</span>
            <span class="wd-select-chevron" aria-hidden="true"></span>
        </button>
        <div v-if="open" class="wd-select-panel" @keydown="onPanelKey">
            <div v-if="searchable" class="wd-select-search-wrap">
                <svg
                    v-if="showSearchIcon"
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
                    :id="searchId"
                    ref="search"
                    class="wd-select-search"
                    :class="{ 'has-icon': showSearchIcon }"
                    type="search"
                    role="combobox"
                    :aria-label="searchAria"
                    :aria-controls="listId"
                    aria-expanded="true"
                    aria-autocomplete="list"
                    :aria-activedescendant="activeId"
                    autocomplete="off"
                    :placeholder="searchPlaceholder"
                    v-model="query"
                />
            </div>
            <ul :id="listId" ref="list" class="wd-select-options" role="listbox" :aria-labelledby="`${id}-label`">
                <li v-if="!filtered.length" class="wd-select-empty" role="status">{{ emptyText }}</li>
                <li v-for="(option, index) in filtered" :key="optionId(option.value)" role="presentation">
                    <button
                        :id="optionId(option.value)"
                        type="button"
                        class="wd-select-option"
                        :class="{ 'is-active': index === active, 'is-rich': showMark || option.hint }"
                        role="option"
                        :aria-selected="option.value === value ? 'true' : 'false'"
                        @mouseenter="active = index"
                        @focus="active = index"
                        @click="choose(option)"
                    >
                        <span class="wd-select-option-copy">
                            <span>{{ option.label }}</span>
                            <span v-if="option.hint" class="wd-select-option-hint">{{ option.hint }}</span>
                        </span>
                        <svg
                            v-if="showMark && option.value === value"
                            class="wd-select-mark"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M5 12l5 5 9-10" />
                        </svg>
                    </button>
                </li>
            </ul>
        </div>
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
<style scoped>
.wd-select-option.is-rich {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.wd-select-option-copy {
    display: flex;
    align-items: baseline;
    gap: 8px;
    min-width: 0;
}
.wd-select-option-hint {
    color: var(--wd-muted);
    font-size: 13px;
    font-weight: 400;
}
.wd-select-mark {
    flex: none;
    color: var(--wd-brand);
}
.wd-select-search-wrap {
    position: relative;
}
.wd-select-search-icon {
    position: absolute;
    top: 12px;
    left: 12px;
    color: var(--wd-muted);
    pointer-events: none;
}
.wd-select-search.has-icon {
    height: 40px;
    min-height: 40px;
    padding-left: 36px;
}
</style>
