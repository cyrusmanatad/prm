<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';

defineProps<{ statusCode: number; message: string }>();
</script>

<template>
    <Head title="Please try again" />
    <FoundationShell>
        <section class="wd-card mx-auto max-w-xl" aria-labelledby="error-title">
            <span class="wd-eyebrow">{{ statusCode }}</span>
            <h1 id="error-title" class="my-4 text-2xl font-semibold">{{ message }}</h1>
            <a class="wd-link" href="/">Return to WeavDash</a>
        </section>
    </FoundationShell>
</template>
