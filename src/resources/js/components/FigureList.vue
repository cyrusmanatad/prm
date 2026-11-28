<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type Figure = {
    label: string;
    value: string | number;
    scope?: string;
    /** Highlights a figure that needs action, such as awards waiting for review. */
    attention?: boolean;
    href?: string;
    linkLabel?: string;
};

defineProps<{ label: string; figures: Figure[] }>();
</script>

<template>
    <ul class="wd-figures" :aria-label="label">
        <li
            v-for="figure in figures"
            :key="figure.label"
            class="wd-figure"
            :class="{ 'is-attention': figure.attention }"
        >
            <span class="wd-figure-value">{{
                typeof figure.value === 'number' ? figure.value.toLocaleString('en') : figure.value
            }}</span>
            <span class="wd-figure-label">{{ figure.label }}</span>
            <Link v-if="figure.href" class="wd-link wd-figure-scope" :href="figure.href">{{
                figure.linkLabel ?? figure.scope
            }}</Link>
            <span v-else-if="figure.scope" class="wd-figure-scope">{{ figure.scope }}</span>
        </li>
    </ul>
</template>
