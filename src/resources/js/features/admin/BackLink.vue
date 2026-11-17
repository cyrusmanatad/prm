<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

defineProps<{ href: string; label: string }>();
</script>

<template>
    <Link :href="href" class="wd-link adm-back"
        ><ArrowLeft :size="16" aria-hidden="true" focusable="false" />{{ label }}</Link
    >
</template>
