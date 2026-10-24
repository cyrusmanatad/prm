<script setup lang="ts">
import { useId } from 'vue';

defineProps<{ title: string; description?: string }>();
const headingId = useId();
</script>

<template>
    <section class="wd-card wd-content-section" :aria-labelledby="headingId">
        <header>
            <h2 :id="headingId">{{ title }}</h2>
            <p v-if="description" class="wd-description">{{ description }}</p>
        </header>
        <slot />
    </section>
</template>
