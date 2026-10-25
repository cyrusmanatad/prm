<script setup lang="ts">
defineProps<{ label?: string }>();
</script>
<template>
    <div class="wd-action-group" :role="label ? 'group' : undefined" :aria-label="label"><slot /></div>
</template>
