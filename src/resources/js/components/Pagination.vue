<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

const props = withDefaults(
    defineProps<{
        label: string;
        page: number;
        perPage: number;
        /** Rows shown on this page. */
        count: number;
        /** Matching records across all pages; null when the server cannot supply it. */
        total: number | null;
        noun?: string;
        hrefFor: (page: number) => string;
    }>(),
    { noun: 'records' },
);
const first = computed(() => (props.page - 1) * props.perPage + 1);
const last = computed(() => first.value + props.count - 1);
const hasNext = computed(() => (props.total === null ? props.count === props.perPage : last.value < props.total));
const summary = computed(() => {
    if (!props.count) return props.total ? `No ${props.noun} on this page` : `No ${props.noun}`;
    if (props.total === null) return `Showing ${props.count} ${props.noun} on this page; total unavailable`;
    return `Showing ${first.value}–${last.value} of ${props.total} matching ${props.noun}, all pages`;
});
</script>

<template>
    <nav class="wd-pagination" :aria-label="label">
        <p role="status">{{ summary }}</p>
        <div v-if="page > 1 || hasNext" class="wd-pagination-links">
            <Link v-if="page > 1" :href="hrefFor(page - 1)" preserve-scroll
                ><ChevronLeft :size="16" aria-hidden="true" />Previous</Link
            >
            <span v-else aria-disabled="true"><ChevronLeft :size="16" aria-hidden="true" />Previous</span>
            <Link v-if="hasNext" :href="hrefFor(page + 1)" preserve-scroll
                >Next<ChevronRight :size="16" aria-hidden="true"
            /></Link>
            <span v-else aria-disabled="true">Next<ChevronRight :size="16" aria-hidden="true" /></span>
        </div>
    </nav>
</template>
