<script setup lang="ts">
defineProps<{ accountName?: string }>();
defineEmits<{ logout: [] }>();
</script>

<template>
    <div class="wd-shell">
        <header class="wd-header">
            <span class="wd-brand">
                <svg viewBox="0 0 36 36" width="36" height="36" aria-hidden="true">
                    <rect width="36" height="36" rx="12" fill="currentColor" />
                    <path d="m8 12 5 14 5-10 5 10 5-14" fill="none" stroke="#FBF7EF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                WeavDash<span class="wd-brand-dot">.</span>
            </span>
            <div v-if="accountName" class="wd-account">
                <span>{{ accountName }}</span>
                <button type="button" class="wd-link" @click="$emit('logout')">Sign out</button>
            </div>
        </header>
        <main id="main-content" class="wd-main"><slot /></main>
    </div>
</template>
