<script setup lang="ts">
import { initials as nameInitials } from '../lib/initials';
import { computed, inject, type Component } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ClipboardCheck,
    ClipboardList,
    ContactRound,
    FileText,
    LogOut,
    Settings,
    Share2,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'reka-ui';

export type AccountMenuLink = { href: string; label: string; icon: Component };

const props = withDefaults(
    defineProps<{
        accountName: string;
        accountEmail?: string;
        initials?: string;
        fixture?: boolean;
        tone?: 'inverse' | 'light';
        /** Design fixtures only; real pages derive workspace tools from the selected workspace. */
        links?: AccountMenuLink[];
    }>(),
    { fixture: false, tone: 'inverse', links: () => [] },
);

const emit = defineEmits<{ logout: [] }>();

const letters = computed(() => props.initials ?? nameInitials(props.accountName, ''));

// Workspace tools, offered on account, workspace selection and other pages outside the portal shell while that kind
// of workspace is selected. Inside the portal shell the sidebar lists them, and Professional workspaces always reach
// their tools from their own navigation.
const partnerTools: AccountMenuLink[] = [
    { href: '/partner/contacts', label: 'Contacts and reminders', icon: ContactRound },
    { href: '/partner/outreach-templates', label: 'Outreach templates', icon: FileText },
    { href: '/partner/onboarding', label: 'Onboarding requests', icon: ClipboardList },
    { href: '/partner/brand-sharing', label: 'Brand sharing', icon: Share2 },
];
const brandTools: AccountMenuLink[] = [
    { href: '/brand', label: 'Partner hub', icon: Users },
    { href: '/brand/lead-registrations', label: 'Lead registrations', icon: ClipboardCheck },
];

// Shown to accounts holding a platform grant, outside administration itself. The server authorizes every admin request.
const page = usePage<{
    platformAccess?: boolean;
    accountClosing?: boolean;
    activeWorkspace?: { kind: string; experience: string } | null;
}>();
const closing = computed(() => page.props?.accountClosing === true);
const workspace = computed(() => page.props?.activeWorkspace);
const insidePortal = inject<boolean>('portalShell', false);
// `links` is only for design fixtures, which have no selected workspace.
const tools = computed(() =>
    props.links.length
        ? props.links
        : insidePortal
          ? []
          : workspace.value?.kind === 'creator' && workspace.value.experience === 'partner'
            ? partnerTools
            : workspace.value?.kind === 'brand'
              ? brandTools
              : [],
);
const showAdministration = computed(
    () => !props.fixture && page.props?.platformAccess === true && !/^\/admin(?:[/?]|$)/.test(page.url ?? ''),
);

function signOut(): void {
    if (!props.fixture) emit('logout');
}
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child>
            <button type="button" class="wd-account-trigger" :class="{ 'wd-account-trigger-light': tone === 'light' }">
                <span class="wd-sr-only">Account menu: </span>
                <span class="wd-avatar">{{ letters }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <nav aria-label="Account">
                <DropdownMenuContent align="end" :side-offset="8" class="wd-account-menu">
                    <div class="wd-account-menu-identity">
                        <span class="wd-account-name">{{ accountName }}</span>
                        <span v-if="accountEmail" class="wd-account-email">{{ accountEmail }}</span>
                    </div>
                    <DropdownMenuItem v-if="!closing" as-child>
                        <Link href="/workspaces"
                            ><Settings :size="18" aria-hidden="true" class="shrink-0" />Switch workspace / account
                            security</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-else as-child>
                        <Link href="/account/closure"
                            ><Settings :size="18" aria-hidden="true" class="shrink-0" />Account closure</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="closing" as-child>
                        <Link href="/account/authenticator"
                            ><ShieldCheck :size="18" aria-hidden="true" class="shrink-0" />Authenticator security</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="!closing" as-child>
                        <Link href="/account/exports"
                            ><ShieldCheck :size="18" aria-hidden="true" class="shrink-0" />Privacy exports</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-for="link in closing ? [] : tools" :key="link.href" as-child>
                        <Link :href="link.href"
                            ><component :is="link.icon" :size="18" aria-hidden="true" class="shrink-0" />{{
                                link.label
                            }}</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="showAdministration && !closing" as-child>
                        <Link href="/admin"
                            ><ShieldCheck :size="18" aria-hidden="true" class="shrink-0" />Platform administration</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="signOut">
                        <LogOut :size="18" aria-hidden="true" class="shrink-0" />Sign out
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </nav>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
