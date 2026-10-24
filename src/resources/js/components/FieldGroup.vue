<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ id: string; label: string; hint?: string; error?: string }>();
const describedby = computed(
    () =>
        [props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : ''].filter(Boolean).join(' ') ||
        undefined,
);
</script>

<template>
    <div class="wd-field">
        <label :for="id">{{ label }}</label>
        <slot :id="id" :describedby="describedby" :invalid="error ? true : undefined" />
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
