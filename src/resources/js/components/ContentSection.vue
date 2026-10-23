<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
  <section class="wd-card wd-content-section" :aria-label="title">
    <header>
      <h2>{{ title }}</h2>
      <p v-if="description" class="wd-description">{{ description }}</p>
    </header>
    <slot />
  </section>
</template>
