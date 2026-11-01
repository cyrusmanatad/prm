<script setup lang="ts">
// One compact note for deferred capabilities, instead of placeholder cards that crowd out working features.
defineProps<{ items: string[] }>();
</script>

<template>
    <section
        aria-label="Coming soon"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-dashed border-line-strong px-4 py-3 text-[13px] text-ink-soft"
    >
        <span class="rounded-full bg-cream px-2.5 py-1 text-[12px] font-bold text-brand">Coming soon</span>
        <ul class="flex flex-wrap gap-x-3 gap-y-1">
            <li v-for="item in items" :key="item">{{ item }}</li>
        </ul>
    </section>
</template>
