<script setup lang="ts">
defineProps<{ label: string; disabled?: boolean }>();
defineEmits<{ click: [] }>();
</script>

<template>
    <button type="button" class="wd-dialog-close" :aria-label="label" :disabled="disabled" @click="$emit('click')">
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            <path
                d="M3.2 3.2 12.8 12.8M12.8 3.2 3.2 12.8"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            />
        </svg>
    </button>
</template>
