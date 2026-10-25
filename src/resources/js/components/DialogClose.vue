<script setup lang="ts">
import { X } from '@lucide/vue';
defineProps<{ label: string; disabled?: boolean }>();
defineEmits<{ click: [] }>();
</script>

<template>
    <button type="button" class="wd-dialog-close" :aria-label="label" :disabled="disabled" @click="$emit('click')">
        <X :size="18" aria-hidden="true" focusable="false" />
    </button>
</template>
