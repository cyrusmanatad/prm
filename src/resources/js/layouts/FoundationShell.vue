<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ProfessionalShell from './ProfessionalShell.vue';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { isProfessionalTool } from '../features/portals/professionalNavigation';
import WorkspaceNotice from '../components/WorkspaceNotice.vue';
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';

defineProps<{
    accountName?: string;
    accountEmail?: string;
    portal?: 'brand' | 'partner' | 'professional';
}>();
defineEmits<{ logout: [] }>();
const { professional } = useWorkspaceExperience();
const page = usePage();
const showProfessional = computed(() => professional.value && isProfessionalTool(page.url));
</script>

<template>
    <ProfessionalShell
        v-if="showProfessional"
        :account-name="accountName"
        :account-email="accountEmail"
        @logout="$emit('logout')"
        ><slot
    /></ProfessionalShell>
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
