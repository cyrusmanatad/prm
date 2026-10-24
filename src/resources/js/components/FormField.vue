<script setup lang="ts">
withDefaults(
    defineProps<{
        id: string;
        label: string;
        type?: 'text' | 'email' | 'password' | 'datetime-local';
        inputmode?: 'text' | 'decimal';
        autocomplete?: string;
        error?: string;
        hint?: string;
        required?: boolean;
        maxlength?: number;
        minlength?: number;
        pattern?: string;
    }>(),
    { type: 'text', required: true },
);

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
            :inputmode="inputmode"
            :autocomplete="autocomplete"
            :required="required"
            :maxlength="maxlength"
            :minlength="minlength"
            :pattern="pattern"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="
                [hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined
            "
        />
        <slot />
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
