<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';

/** Trail to the current record. The last item is the current page and has no link. */
defineProps<{ items: { label: string; href?: string }[] }>();
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="wd-crumbs">
            <li
                v-for="(item, index) in items"
                :key="index"
                :aria-current="index === items.length - 1 ? 'page' : undefined"
            >
                <template v-if="item.href && index < items.length - 1">
                    <Link :href="item.href" class="wd-link">{{ item.label }}</Link
                    ><ChevronRight :size="14" aria-hidden="true" />
                </template>
                <template v-else>{{ item.label }}</template>
            </li>
        </ol>
    </nav>
</template>
