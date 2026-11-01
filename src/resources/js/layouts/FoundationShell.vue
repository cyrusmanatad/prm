<script setup lang="ts">
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';

defineProps<{ accountName?: string; accountEmail?: string; portal?: 'brand' | 'pro' }>();
defineEmits<{ logout: [] }>();
</script>

<template>
    <div class="wd-shell">
        <a class="wd-skip-link" href="#main-content">Skip to main content</a>
        <header class="wd-header" :class="{ 'wd-header-brand': portal === 'brand' }">
            <div class="wd-header-inner">
                <span class="wd-brand">
                    <BrandLogo />
                    <span class="wd-wordmark">WeavDash</span
                    ><span v-if="portal">{{ portal === 'brand' ? 'Brand' : 'Professional' }}</span>
                </span>
                <div v-if="accountName" class="wd-account">
                    <AccountMenu :account-name="accountName" :account-email="accountEmail" @logout="$emit('logout')" />
                </div>
            </div>
        </header>
        <main id="main-content" class="wd-main"><slot /></main>
    </div>
</template>
