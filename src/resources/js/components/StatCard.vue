<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    label: string;
    /** null means the figure could not be observed; it is never shown as zero. */
    value: number | null;
    scope: string;
    href?: string;
    linkLabel?: string;
}>();
</script>

<template>
    <article class="wd-stat-card">
        <h3>{{ label }}</h3>
        <p v-if="value !== null" class="wd-stat-value">{{ value.toLocaleString('en') }}</p>
        <p v-else class="wd-stat-value wd-stat-value-unavailable">Unavailable</p>
        <p class="wd-hint">{{ scope }}</p>
        <Link v-if="href" :href="href">{{ linkLabel ?? `View ${label.toLowerCase()}` }}</Link>
    </article>
</template>
