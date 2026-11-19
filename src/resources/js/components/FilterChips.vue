<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type FilterChip = { value: string; label: string; count: number };

defineProps<{
    /** Accessible name of the chip group, for example "Filter by stage". */
    label: string;
    chips: FilterChip[];
    current: string;
    hrefFor: (value: string) => string;
}>();
</script>

<template>
    <nav class="wd-filter-chips" :aria-label="label">
        <ul>
            <li v-for="chip in chips" :key="chip.value || 'all'">
                <Link
                    :href="hrefFor(chip.value)"
                    v-bind="{ 'aria-current': chip.value === current ? 'true' : undefined }"
                    preserve-scroll
                >
                    {{ chip.label }}
                    <span class="wd-filter-chip-count">{{ chip.count }}</span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
