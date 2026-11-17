<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type TabLink = { href: string; label: string; current: boolean };

defineProps<{ label: string; tabs: TabLink[] }>();
</script>

<template>
    <nav class="wd-tabs" :aria-label="label">
        <ul>
            <li v-for="tab in tabs" :key="tab.href">
                <Link :href="tab.href" v-bind="{ 'aria-current': tab.current ? 'page' : undefined }" preserve-scroll>{{
                    tab.label
                }}</Link>
            </li>
        </ul>
    </nav>
</template>
