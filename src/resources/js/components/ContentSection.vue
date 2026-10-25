<script setup lang="ts">
import { useId } from 'vue';

defineProps<{ title: string; description?: string; headingId?: string }>();
const generatedHeadingId = useId();
</script>

<template>
    <section class="wd-card wd-content-section" :aria-labelledby="headingId ?? generatedHeadingId">
        <header>
            <h2 :id="headingId ?? generatedHeadingId">{{ title }}</h2>
            <p v-if="description" class="wd-description">{{ description }}</p>
        </header>
        <slot />
    </section>
</template>
