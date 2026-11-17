<script setup lang="ts">
import ActionGroup from './ActionGroup.vue';
import StatusBadge from './StatusBadge.vue';
import { Button } from './ui/button';

export type AppliedFilter = { label: string; value: string };

withDefaults(
    defineProps<{
        label: string;
        /** Filters the server has applied to the records now shown. */
        applied?: AppliedFilter[];
        pending?: boolean;
        submitLabel?: string;
    }>(),
    { applied: () => [], submitLabel: 'Apply filters' },
);
defineEmits<{ submit: []; clear: [] }>();
</script>

<template>
    <form class="wd-filter-bar" role="search" :aria-label="label" @submit.prevent="$emit('submit')">
        <div class="wd-toolbar">
            <slot />
            <ActionGroup>
                <Button type="submit" class="wd-button wd-button-secondary" :disabled="pending">{{
                    submitLabel
                }}</Button>
                <Button
                    v-if="applied.length"
                    type="button"
                    class="wd-button wd-button-secondary"
                    :disabled="pending"
                    @click="$emit('clear')"
                    >Clear filters</Button
                >
            </ActionGroup>
        </div>
        <p v-if="pending" class="wd-hint" role="status">Updating results…</p>
        <ul v-else-if="applied.length" class="wd-filter-summary" aria-label="Applied filters">
            <li v-for="filter in applied" :key="filter.label">
                <StatusBadge :label="`${filter.label}: ${filter.value}`" />
            </li>
        </ul>
    </form>
</template>
