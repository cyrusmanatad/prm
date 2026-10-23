<script setup lang="ts">
defineProps<{ id: string; label: string; hint?: string }>();
</script>

<template>
  <div class="wd-field">
    <label :for="id">{{ label }}</label>
    <slot :id="id" :describedby="hint ? `${id}-hint` : undefined" />
    <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
  </div>
</template>
