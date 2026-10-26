<script setup lang="ts">
defineProps<{ prefix: string; error?: string }>();
const enabled = defineModel<boolean>({ default: false });
</script>

<template>
    <div class="wd-fields">
        <label :for="`${prefix}-notify-submission`" class="flex items-start gap-2">
            <input
                :id="`${prefix}-notify-submission`"
                v-model="enabled"
                type="checkbox"
                class="shrink-0 accent-[var(--wd-brand)]"
                :aria-describedby="`${prefix}-notify-hint`"
            />
            Email me when this form is submitted
        </label>
        <p :id="`${prefix}-notify-hint`" class="wd-hint">
            Optional. Notify your verified account while you still have permission to review this request. A replacement
            invitation requires a new selection.
        </p>
        <p v-if="error" class="wd-field-error" role="alert">{{ error }}</p>
    </div>
</template>
