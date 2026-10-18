<script setup lang="ts">
withDefaults(defineProps<{
    id: string;
    label: string;
    type?: 'text' | 'email' | 'password';
    autocomplete?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    maxlength?: number;
    minlength?: number;
}>(), { type: 'text', required: true });

const value = defineModel<string>({ default: '' });
</script>

<template>
    <div class="wd-field">
        <label :for="id">{{ label }}</label>
        <input
            :id="id"
            v-model="value"
            :name="id"
            :type="type"
            :autocomplete="autocomplete"
            :required="required"
            :maxlength="maxlength"
            :minlength="minlength"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="[hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined"
        >
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
