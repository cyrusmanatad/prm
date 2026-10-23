<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
defineProps<{
    accountName: string;
    title: string;
    active: 'invoices' | 'payments' | 'entitlements';
    workspaceName?: string;
    status?: string;
    errors?: Record<string, string>;
}>();
const links = [
    { key: 'workspaces', href: '/workspaces', label: 'Workspaces' },
    { key: 'invoices', href: '/finance/invoices', label: 'Private invoice drafts' },
    { key: 'payments', href: '/finance/payments', label: 'Manual payments' },
    { key: 'entitlements', href: '/workspace/entitlements', label: 'Pilot entitlements' },
];
</script>

<template>
    <FoundationShell :account-name="accountName" @logout="router.post('/logout')">
        <div class="wd-workspace-content wd-finance">
            <nav class="wd-finance-nav" aria-label="Finance">
                <Link
                    v-for="link in links"
                    :key="link.key"
                    :href="link.href"
                    v-bind="{ 'aria-current': active === link.key ? 'page' : undefined }"
                    >{{ active === 'entitlements' && link.key === 'invoices' ? 'Finance' : link.label }}</Link
                >
            </nav>
            <header class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspaceName ?? 'Workspace' }} · Finance</span>
                <h1>{{ title }}</h1>
                <div class="wd-description"><slot name="description" /></div>
            </header>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <div v-if="Object.keys(errors ?? {}).length" role="alert" class="wd-banner">
                <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
                <slot name="error-action" />
            </div>
            <div class="wd-finance-sections"><slot /></div>
        </div>
    </FoundationShell>
</template>
