<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BrandChrome from '../features/portals/BrandChrome.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import HomeView from '../features/home/HomeView.vue';
import type { BrandHome, CreatorHome } from '../features/home/types';
import type { PortalExperience } from '../features/portals/portalNavigation';

const props = defineProps<{
    account: { name: string; email: string };
    experience: PortalExperience;
    workspace: { id: string; name: string };
    home: BrandHome | CreatorHome;
}>();
const brandHome = () => props.home as BrandHome;
const creatorHome = () => props.home as CreatorHome;
</script>

<template>
    <Head title="Home" />
    <BrandChrome
        v-if="experience === 'brand'"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="wd-home-main">
            <HomeView experience="brand" :home="brandHome()" :workspace-name="workspace.name" />
        </main>
    </BrandChrome>
    <PartnerChrome v-else :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="wd-home-main">
            <HomeView :experience="experience" :home="creatorHome()" :workspace-name="workspace.name" />
        </main>
    </PartnerChrome>
</template>
