<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';

const props = defineProps<{ accountName?: string; portal?: 'brand' | 'partner' }>();
defineEmits<{ logout: [] }>();
const initials = computed(() =>
    (props.accountName ?? '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.slice(0, 1).toUpperCase())
        .join(''),
);
</script>

<template>
    <div class="wd-shell">
        <header class="wd-header" :class="{ 'wd-header-brand': portal === 'brand' }">
            <div class="wd-header-inner">
                <span class="wd-brand">
                    <BrandLogo />
                    <span class="wd-wordmark">WeavDash</span
                    ><span v-if="portal">{{ portal === 'brand' ? 'Brand' : 'Partner' }}</span>
                </span>
                <div v-if="accountName" class="wd-account">
                    <span class="wd-account-name">{{ accountName }}</span>
                    <button type="button" class="wd-link wd-header-link" @click="$emit('logout')">Sign out</button>
                    <span class="wd-avatar" aria-hidden="true">{{ initials }}</span>
                </div>
            </div>
        </header>
        <main id="main-content" class="wd-main"><slot /></main>
    </div>
</template>
