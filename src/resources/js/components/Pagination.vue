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
        /** Offer direct links to nearby pages. Needs a known total. */
        numbered?: boolean;
    }>(),
    { noun: 'records', numbered: false },
);
const first = computed(() => (props.page - 1) * props.perPage + 1);
const last = computed(() => first.value + props.count - 1);
const hasNext = computed(() => (props.total === null ? props.count === props.perPage : last.value < props.total));
const pageCount = computed(() => (props.total ? Math.ceil(props.total / props.perPage) : 0));
/** First, last and the pages around the current one; null marks a gap. */
const pages = computed<(number | null)[]>(() => {
    if (!props.numbered || pageCount.value < 2) return [];
    const shown = new Set([1, pageCount.value, props.page - 1, props.page, props.page + 1]);
    const list = [...shown].filter((page) => page >= 1 && page <= pageCount.value).sort((a, b) => a - b);
    return list.flatMap((page, index) => (index && page - list[index - 1]! > 1 ? [null, page] : [page]));
});
const summary = computed(() => {
    if (!props.count) return props.total ? `No ${props.noun} on this page` : `No ${props.noun}`;
    if (props.total === null) return `Showing ${props.count} ${props.noun} on this page; total unavailable`;
    return `Showing ${first.value}–${last.value} of ${props.total} matching ${props.noun}, all pages`;
});
</script>

<template>
    <nav class="wd-pagination" :aria-label="label">
        <p role="status">{{ summary }}</p>
        <div class="wd-pagination-controls">
            <slot />
            <div v-if="page > 1 || hasNext" class="wd-pagination-links">
                <Link v-if="page > 1" :href="hrefFor(page - 1)" preserve-scroll
                    ><ChevronLeft :size="16" aria-hidden="true" />Previous</Link
                >
                <span v-else aria-disabled="true"><ChevronLeft :size="16" aria-hidden="true" />Previous</span>
                <template v-for="(item, index) in pages" :key="item ?? `gap-${index}`">
                    <span v-if="item === null" class="wd-pagination-gap" aria-hidden="true">…</span>
                    <Link
                        v-else
                        class="wd-pagination-page"
                        :href="hrefFor(item)"
                        v-bind="{ 'aria-current': item === page ? 'page' : undefined, 'aria-label': `Page ${item}` }"
                        preserve-scroll
                        >{{ item }}</Link
                    >
                </template>
                <Link v-if="hasNext" :href="hrefFor(page + 1)" preserve-scroll
                    >Next<ChevronRight :size="16" aria-hidden="true"
                /></Link>
                <span v-else aria-disabled="true">Next<ChevronRight :size="16" aria-hidden="true" /></span>
            </div>
        </div>
    </nav>
</template>
