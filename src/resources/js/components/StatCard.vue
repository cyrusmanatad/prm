<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';

defineProps<{
    label: string;
    /** null means the figure could not be observed; it is never shown as zero. */
    value: number | null;
    scope: string;
    href?: string;
    linkLabel?: string;
    /** One linked line (figure, label, scope) for record headers; needs `href`. */
    compact?: boolean;
}>();
</script>

<template>
    <Link v-if="compact && href" :href="href" class="wd-stat-chip">
        <span v-if="value !== null" class="wd-stat-chip-value">{{ value.toLocaleString('en') }}</span>
        <span v-else class="wd-stat-chip-value wd-stat-value-unavailable">Unavailable</span>
        <span class="wd-stat-chip-label"
            >{{ label }}<span class="wd-stat-chip-scope">{{ scope }}</span
            ><span class="wd-sr-only">. {{ linkLabel ?? `View ${label.toLowerCase()}` }}</span></span
        >
        <ChevronRight :size="16" aria-hidden="true" />
    </Link>
    <article v-else class="wd-stat-card">
        <h3>{{ label }}</h3>
        <p v-if="value !== null" class="wd-stat-value">{{ value.toLocaleString('en') }}</p>
        <p v-else class="wd-stat-value wd-stat-value-unavailable">Unavailable</p>
        <p class="wd-hint">{{ scope }}</p>
        <Link v-if="href" :href="href">{{ linkLabel ?? `View ${label.toLowerCase()}` }}</Link>
    </article>
</template>
