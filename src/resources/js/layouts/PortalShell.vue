<script setup lang="ts">
import '../../css/portal-shell.css';
import { computed, onMounted, provide, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, PanelLeftClose, PanelLeftOpen, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    DialogTrigger,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';
import PortalNav from '../features/portals/PortalNav.vue';
import { experienceLabel, type PortalExperience } from '../features/portals/portalNavigation';
import { usePortalContext } from '../composables/usePortalContext';

const props = withDefaults(
    defineProps<{
        experience: PortalExperience;
        accountName?: string;
        accountEmail?: string;
        /** Overrides the shared workspace name, for example on design fixtures. */
        workspaceName?: string;
        /** Brand keeps its approved white header (D23); Partner and Professional use burgundy. */
        tone?: 'inverse' | 'light';
        /** False when the page supplies its own `<main id="main-content">`. */
        containMain?: boolean;
        mainClass?: string;
        /** Existing page-family classes, so content keeps its approved typography and spacing. */
        rootClass?: string;
        /** Where the logo leads; omitted keeps the logo unlinked. Home is not substituted for existing landings. */
        logoHref?: string;
        initials?: string;
        fixture?: boolean;
    }>(),
    { tone: 'inverse', containMain: true, fixture: false },
);
defineEmits<{ logout: [] }>();

// Account menus inside the portal leave workspace tools to the sidebar.
provide('portalShell', true);

const page = usePage();
const { owner, workspaceName: sharedName } = usePortalContext();
const workspaceName = computed(() => props.workspaceName ?? sharedName.value);
const label = computed(() => experienceLabel[props.experience]);

const STORAGE_KEY = 'weavdash.portal.sidebar';
const collapsed = ref(false);
onMounted(() => {
    // Display preference only; storage can be unavailable or blocked.
    try {
        collapsed.value = window.localStorage.getItem(STORAGE_KEY) === 'collapsed';
    } catch {
        collapsed.value = false;
    }
});
function toggleCollapsed(): void {
    collapsed.value = !collapsed.value;
    try {
        window.localStorage.setItem(STORAGE_KEY, collapsed.value ? 'collapsed' : 'expanded');
    } catch {
        // The preference still applies for this page.
    }
}

const drawerOpen = ref(false);
// Close the drawer once a navigation completes, including one that keeps the same page component.
watch(
    () => page.url,
    () => {
        drawerOpen.value = false;
    },
);
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <div
            class="wd-portal"
            :class="[rootClass, { 'is-collapsed': collapsed, 'wd-portal-light': tone === 'light' }]"
            @click="fixture && $event.preventDefault()"
        >
            <a class="wd-skip-link" href="#main-content">Skip to main content</a>
            <header class="wd-portal-header">
                <div class="wd-portal-header-inner">
                    <DialogRoot v-model:open="drawerOpen">
                        <DialogTrigger as-child>
                            <button type="button" class="wd-portal-menu-button">
                                <Menu :size="20" aria-hidden="true" /><span class="wd-sr-only">Menu</span>
                            </button>
                        </DialogTrigger>
                        <DialogPortal>
                            <DialogOverlay class="wd-portal-drawer-overlay" />
                            <DialogContent class="wd-portal-drawer">
                                <div class="wd-portal-drawer-header">
                                    <div class="wd-portal-drawer-heading">
                                        <DialogTitle class="wd-portal-drawer-title">{{ label }} menu</DialogTitle>
                                        <DialogDescription class="wd-portal-drawer-workspace"
                                            ><span class="wd-sr-only">Workspace tools for </span
                                            >{{ workspaceName || label }}</DialogDescription
                                        >
                                    </div>
                                    <DialogClose class="wd-portal-drawer-close"
                                        ><X :size="20" aria-hidden="true" /><span class="wd-sr-only"
                                            >Close menu</span
                                        ></DialogClose
                                    >
                                </div>
                                <PortalNav
                                    :experience="experience"
                                    :owner="owner"
                                    :fixture="fixture"
                                    id-prefix="portal-drawer"
                                    @navigate="drawerOpen = false"
                                />
                            </DialogContent>
                        </DialogPortal>
                    </DialogRoot>
                    <div class="wd-portal-masthead">
                        <component :is="logoHref && !fixture ? Link : 'span'" :href="logoHref" class="wd-portal-brand">
                            <BrandLogo /><span class="wd-wordmark">WeavDash</span>
                        </component>
                        <p class="wd-portal-identity">
                            <span class="wd-portal-experience">{{ label }}</span>
                            <span v-if="workspaceName" class="wd-portal-workspace" :title="workspaceName"
                                ><span class="wd-sr-only">workspace: </span>{{ workspaceName }}</span
                            >
                        </p>
                    </div>
                    <div class="wd-portal-actions">
                        <slot name="actions" />
                        <AccountMenu
                            v-if="accountName"
                            :account-name="accountName"
                            :account-email="accountEmail"
                            :initials="initials"
                            :fixture="fixture"
                            :tone="tone"
                            @logout="$emit('logout')"
                        />
                    </div>
                </div>
            </header>
            <div class="wd-portal-body">
                <div class="wd-portal-sidebar">
                    <div class="wd-portal-sidebar-inner">
                        <div id="portal-sidebar" class="wd-portal-sidebar-scroll">
                            <PortalNav
                                :experience="experience"
                                :owner="owner"
                                :collapsed="collapsed"
                                :fixture="fixture"
                                id-prefix="portal-sidebar"
                            />
                        </div>
                        <TooltipRoot :disabled="!collapsed">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="wd-portal-collapse"
                                    aria-controls="portal-sidebar"
                                    :aria-expanded="!collapsed"
                                    @click="toggleCollapsed"
                                >
                                    <component
                                        :is="collapsed ? PanelLeftOpen : PanelLeftClose"
                                        :size="20"
                                        aria-hidden="true"
                                    />
                                    <span :class="{ 'wd-sr-only': collapsed }">{{
                                        collapsed ? 'Expand navigation' : 'Collapse'
                                    }}</span>
                                    <span v-if="!collapsed" class="wd-sr-only"> navigation</span>
                                </button>
                            </TooltipTrigger>
                            <TooltipPortal>
                                <TooltipContent side="right" :side-offset="8" class="wd-portal-tooltip"
                                    >Expand navigation</TooltipContent
                                >
                            </TooltipPortal>
                        </TooltipRoot>
                    </div>
                </div>
                <div class="wd-portal-content">
                    <slot name="notice" />
                    <main v-if="containMain" id="main-content" :class="mainClass"><slot /></main>
                    <slot v-else />
                </div>
            </div>
        </div>
    </TooltipProvider>
</template>
