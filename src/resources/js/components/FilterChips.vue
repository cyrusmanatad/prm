<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type FilterChip = { value: string; label: string; count: number };

defineProps<{
    /** Accessible name of the chip group, for example "Filter by stage". */
    label: string;
    chips: FilterChip[];
    current: string;
    /** Address of each choice, for server-filtered lists. Without it the chips are toggle buttons that emit `select`. */
    hrefFor?: (value: string) => string;
    /** Smaller chips for secondary sections; phones keep 44px targets. */
    compact?: boolean;
}>();
defineEmits<{ select: [value: string] }>();
</script>

<template>
    <nav v-if="hrefFor" class="wd-filter-chips" :class="{ 'is-compact': compact }" :aria-label="label">
        <ul>
            <li v-for="chip in chips" :key="chip.value || 'all'">
                <Link
                    :href="hrefFor(chip.value)"
                    v-bind="{ 'aria-current': chip.value === current ? 'true' : undefined }"
                    preserve-scroll
                >
                    {{ chip.label }}
                    <span class="wd-filter-chip-count">{{ chip.count.toLocaleString('en-GB') }}</span>
                </Link>
            </li>
        </ul>
    </nav>
    <div v-else class="wd-filter-chips" :class="{ 'is-compact': compact }" role="group" :aria-label="label">
        <ul>
            <li v-for="chip in chips" :key="chip.value || 'all'">
                <button type="button" :aria-pressed="chip.value === current" @click="$emit('select', chip.value)">
                    {{ chip.label }}
                    <span class="wd-filter-chip-count">{{ chip.count.toLocaleString('en-GB') }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
