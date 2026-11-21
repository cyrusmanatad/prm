<script setup lang="ts">
import { nextTick } from 'vue';

/** `badge` is a short warning label shown after the tab name, for example "Action needed". */
export type Tab = { id: string; label: string; count?: number; badge?: string };

/**
 * In-page tabs. Panels are rendered by the parent with `role="tabpanel"`, the id `${idPrefix}-panel-${tab.id}`
 * and `aria-labelledby="${idPrefix}-tab-${tab.id}"`. Arrow keys, Home and End move between tabs.
 */
const props = defineProps<{ label: string; tabs: Tab[]; idPrefix: string }>();
const current = defineModel<string>({ required: true });

async function move(event: KeyboardEvent, index: number): Promise<void> {
    const last = props.tabs.length - 1;
    const next =
        event.key === 'ArrowRight'
            ? (index + 1) % props.tabs.length
            : event.key === 'ArrowLeft'
              ? (index - 1 + props.tabs.length) % props.tabs.length
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? last
                  : -1;
    if (next < 0) return;
    event.preventDefault();
    current.value = props.tabs[next]!.id;
    await nextTick();
    document.getElementById(`${props.idPrefix}-tab-${current.value}`)?.focus();
}
</script>

<template>
    <div class="wd-inline-tabs" role="tablist" :aria-label="label">
        <button
            v-for="(tab, index) in tabs"
            :id="`${idPrefix}-tab-${tab.id}`"
            :key="tab.id"
            type="button"
            role="tab"
            :aria-selected="current === tab.id"
            :aria-controls="`${idPrefix}-panel-${tab.id}`"
            :tabindex="current === tab.id ? 0 : -1"
            @click="current = tab.id"
            @keydown="move($event, index)"
        >
            {{ tab.label }}<span v-if="tab.count !== undefined" class="wd-inline-tabs-count">{{ tab.count }}</span
            ><span v-if="tab.badge" class="wd-inline-tabs-badge">{{ tab.badge }}</span>
        </button>
    </div>
</template>
