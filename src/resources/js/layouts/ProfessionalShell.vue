<script setup lang="ts">
import '../../css/professional.css';
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Menu } from '@lucide/vue';
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuGroup,
    DropdownMenuLabel,
} from 'reka-ui';
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';
import WorkspaceNotice from '../components/WorkspaceNotice.vue';
import { professionalGroups, professionalDestination } from '../features/portals/professionalNavigation';
withDefaults(defineProps<{ accountName?: string; accountEmail?: string; containMain?: boolean }>(), {
    containMain: true,
});
defineEmits<{ logout: [] }>();
const page = usePage();
const mobileOpen = ref(false);
const current = computed(() => professionalDestination(page.url));
const active = (href: string) =>
    current.value === href || (href !== '/professional' && current.value.startsWith(href + '/'));
</script>
<template>
    <div class="wd-shell wd-professional">
        <a class="wd-skip-link" href="#main-content">Skip to main content</a>
        <header class="pro-header">
            <div class="pro-header-inner">
                <Link href="/professional" class="pro-brand"
                    ><BrandLogo /><span class="wd-wordmark">WeavDash</span
                    ><span class="pro-experience">Professional</span></Link
                >
                <nav class="pro-desktop-nav" aria-label="Professional tools">
                    <template v-for="group in professionalGroups" :key="group.label">
                        <Link
                            v-if="group.links.length === 1"
                            :href="group.links[0]!.href"
                            v-bind="{ 'aria-current': active(group.links[0]!.href) ? 'page' : undefined }"
                            >{{ group.label }}</Link
                        >
                        <DropdownMenuRoot v-else :modal="false">
                            <DropdownMenuTrigger
                                class="pro-nav-trigger"
                                :class="{ 'pro-group-active': group.links.some((link) => active(link.href)) }"
                                >{{ group.label }}<ChevronDown :size="14" aria-hidden="true"
                            /></DropdownMenuTrigger>
                            <DropdownMenuContent class="pro-nav-menu" :side-offset="8" align="start">
                                <DropdownMenuItem v-for="link in group.links" :key="link.href" as-child
                                    ><Link
                                        :href="link.href"
                                        v-bind="{ 'aria-current': active(link.href) ? 'page' : undefined }"
                                        >{{ link.label }}</Link
                                    ></DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenuRoot>
                    </template>
                </nav>
                <div class="pro-header-actions">
                    <nav class="pro-mobile-nav" aria-label="Professional tools">
                        <DropdownMenuRoot v-model:open="mobileOpen" :modal="false">
                            <DropdownMenuTrigger class="pro-mobile-trigger"
                                ><Menu :size="18" aria-hidden="true" />Menu</DropdownMenuTrigger
                            >
                            <DropdownMenuContent class="pro-nav-menu pro-mobile-menu" :side-offset="8" align="end">
                                <DropdownMenuGroup
                                    v-for="group in professionalGroups"
                                    :key="group.label"
                                    v-bind="{ 'aria-label': group.label }"
                                    class="pro-mobile-group"
                                >
                                    <DropdownMenuLabel v-if="group.links.length > 1" class="pro-mobile-label">{{
                                        group.label
                                    }}</DropdownMenuLabel>
                                    <DropdownMenuItem v-for="link in group.links" :key="link.href" as-child
                                        ><Link
                                            :href="link.href"
                                            v-bind="{ 'aria-current': active(link.href) ? 'page' : undefined }"
                                            >{{ link.label }}</Link
                                        ></DropdownMenuItem
                                    >
                                </DropdownMenuGroup>
                            </DropdownMenuContent>
                        </DropdownMenuRoot>
                    </nav>
                    <AccountMenu
                        v-if="accountName"
                        :account-name="accountName"
                        :account-email="accountEmail"
                        @logout="$emit('logout')"
                    />
                </div>
            </div>
        </header>
        <div class="pro-access"><WorkspaceNotice compact /></div>
        <main v-if="containMain" id="main-content" class="pro-main"><slot /></main>
        <slot v-else />
    </div>
</template>
