<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ProfessionalShell from './ProfessionalShell.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import { usePortalContext } from '../composables/usePortalContext';
import { isPortalPage } from '../features/portals/portalNavigation';
import WorkspaceNotice from '../components/WorkspaceNotice.vue';
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';

defineProps<{
    accountName?: string;
    accountEmail?: string;
    portal?: 'brand' | 'partner' | 'professional';
}>();
defineEmits<{ logout: [] }>();
const { experience } = usePortalContext();
const page = usePage();
// Workspace tools and their details use the portal shell of the selected workspace; account, workspace selection,
// public and administration pages keep this shell.
const portalShell = computed(() =>
    experience.value && isPortalPage(experience.value, page.url ?? '') ? experience.value : null,
);
const showProfessional = computed(() => portalShell.value === 'professional');
const showPartner = computed(() => portalShell.value === 'partner');
</script>

<template>
    <ProfessionalShell
        v-if="showProfessional"
        :account-name="accountName"
        :account-email="accountEmail"
        @logout="$emit('logout')"
        ><slot
    /></ProfessionalShell>
    <PartnerChrome v-else-if="showPartner && accountName" :account-name="accountName" :account-email="accountEmail"
        ><main id="main-content" class="wd-main"><slot /></main
    ></PartnerChrome>
    <div v-else class="wd-shell">
        <a class="wd-skip-link" href="#main-content">Skip to main content</a>
        <header class="wd-header" :class="{ 'wd-header-brand': portal === 'brand' }">
            <div class="wd-header-inner">
                <span class="wd-brand">
                    <BrandLogo />
                    <span class="wd-wordmark">WeavDash</span
                    ><span v-if="portal">{{
                        portal === 'brand' ? 'Brand' : portal === 'professional' ? 'Professional' : 'Partner'
                    }}</span>
                </span>
                <div v-if="accountName" class="wd-account">
                    <AccountMenu :account-name="accountName" :account-email="accountEmail" @logout="$emit('logout')" />
                </div>
            </div>
        </header>
        <main id="main-content" class="wd-main"><WorkspaceNotice /><slot /></main>
    </div>
</template>
