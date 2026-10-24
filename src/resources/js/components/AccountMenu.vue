<script setup lang="ts">
import { computed, type Component } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
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
        links?: AccountMenuLink[];
    }>(),
    { fixture: false, tone: 'inverse', links: () => [] },
);

const emit = defineEmits<{ logout: [] }>();

const letters = computed(
    () =>
        props.initials ??
        props.accountName
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part.slice(0, 1).toUpperCase())
            .join(''),
);

function signOut(): void {
    if (!props.fixture) emit('logout');
}
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="wd-account-trigger"
                :class="{ 'wd-account-trigger-light': tone === 'light' }"
                aria-label="Account menu"
            >
                <span class="wd-account-identity" aria-hidden="true">
                    <span class="wd-account-name">{{ accountName }}</span>
                    <span v-if="accountEmail" class="wd-account-email">{{ accountEmail }}</span>
                </span>
                <span class="wd-avatar" aria-hidden="true">{{ letters }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <nav aria-label="Account">
                <DropdownMenuContent align="end" :side-offset="8" class="wd-account-menu">
                    <DropdownMenuItem as-child>
                        <Link href="/workspaces"
                            ><Settings :size="18" aria-hidden="true" class="shrink-0" />Switch workspace / account
                            security</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem v-for="link in links" :key="link.href" as-child>
                        <Link :href="link.href"
                            ><component :is="link.icon" :size="18" aria-hidden="true" class="shrink-0" />{{
                                link.label
                            }}</Link
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
