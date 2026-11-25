<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PortalShell from '../../layouts/PortalShell.vue';

const props = defineProps<{
    workspaceName: string;
    accountName: string;
    accountEmail?: string;
    /** The hub offers the invitation dialog; other Brand screens link back to the hub for it. */
    canInvite?: boolean;
    showInvite?: boolean;
    fixture?: boolean;
}>();
defineEmits<{ invite: [] }>();
// Synthetic reference content cannot be enabled in a production build.
const fixture = computed(() => import.meta.env.DEV && props.fixture === true);
const initials = computed(() =>
    props.accountName
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join(''),
);
</script>
<template>
    <PortalShell
        experience="brand"
        tone="light"
        root-class="portal-ui font-sans leading-[normal]"
        :workspace-name="fixture ? 'Habi Home' : workspaceName"
        :account-name="accountName"
        :account-email="fixture ? 'bea@example.test' : accountEmail"
        :initials="initials"
        :fixture="fixture"
        :contain-main="false"
        @logout="router.post('/logout')"
    >
        <template v-if="showInvite" #actions>
            <button
                type="button"
                :disabled="!fixture && !canInvite"
                class="min-h-11 rounded-[10px] bg-brand px-3.5 py-2.5 text-[14px] font-bold whitespace-nowrap text-white"
                @click="$emit('invite')"
            >
                + Invite partners
            </button>
        </template>
        <slot />
    </PortalShell>
</template>
