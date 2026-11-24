<script setup lang="ts">
import '../../css/professional.css';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PortalShell from './PortalShell.vue';
import WorkspaceNotice from '../components/WorkspaceNotice.vue';
import { professionalDestination } from '../features/portals/professionalNavigation';
withDefaults(defineProps<{ accountName?: string; accountEmail?: string; containMain?: boolean }>(), {
    containMain: true,
});
defineEmits<{ logout: [] }>();
const page = usePage();
const current = computed(() => professionalDestination(page.url ?? ''));
</script>
<template>
    <PortalShell
        experience="professional"
        root-class="wd-shell wd-professional"
        main-class="pro-main"
        logo-href="/professional"
        :account-name="accountName"
        :account-email="accountEmail"
        :contain-main="containMain"
        @logout="$emit('logout')"
    >
        <!-- The Hub shows the subscription in its own card. -->
        <template #notice>
            <div v-if="current !== '/professional'" class="pro-access"><WorkspaceNotice compact /></div>
        </template>
        <slot />
    </PortalShell>
</template>
