<script setup lang="ts">
import '../../css/records.css';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
const { professional } = useWorkspaceExperience();
import { Link, router } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
defineProps<{
    accountName: string;
    accountEmail?: string;
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
    <FoundationShell :account-name="accountName" :account-email="accountEmail" @logout="router.post('/logout')">
        <div class="rec-page wd-finance">
            <nav v-if="!professional || active === 'entitlements'" class="wd-finance-nav" aria-label="Finance">
                <Link
                    v-for="link in links"
                    :key="link.key"
                    :href="link.href"
                    v-bind="{ 'aria-current': active === link.key ? 'page' : undefined }"
                    >{{ active === 'entitlements' && link.key === 'invoices' ? 'Finance' : link.label }}</Link
                >
            </nav>
            <header class="rec-header">
                <div>
                    <h1>{{ title }}</h1>
                    <p><slot name="description" /></p>
                </div>
                <div v-if="$slots.actions" class="rec-header-actions"><slot name="actions" /></div>
            </header>
            <div v-if="Object.keys(errors ?? {}).length" role="alert" class="wd-banner rec-banner">
                <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
                <slot name="error-action" />
            </div>
            <slot />
        </div>
    </FoundationShell>
</template>
