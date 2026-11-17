<script setup lang="ts">
import { Lock } from '@lucide/vue';

defineProps<{ title: string; reason: string }>();
</script>

<template>
    <div class="wd-unavailable" role="note">
        <Lock :size="18" aria-hidden="true" focusable="false" />
        <h3>{{ title }}</h3>
        <p>{{ reason }}</p>
        <slot />
    </div>
</template>
