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
            <button type="button" class="wd-account-trigger" :class="{ 'wd-account-trigger-light': tone === 'light' }">
                <span class="wd-sr-only">Account menu: {{ accountName }}</span>
                <!-- The email moves into the menu so headers stay on one row. -->
                <span class="wd-account-name" aria-hidden="true">{{ accountName.split(' ')[0] }}</span>
                <span class="wd-avatar" aria-hidden="true">{{ letters }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <nav aria-label="Account">
                <DropdownMenuContent align="end" :side-offset="8" class="wd-account-menu">
                    <div class="wd-account-menu-identity">
                        <strong>{{ accountName }}</strong>
                        <span v-if="accountEmail">{{ accountEmail }}</span>
                    </div>
                    <DropdownMenuItem as-child>
                        <Link href="/workspaces"
                            ><Settings :size="18" aria-hidden="true" class="shrink-0" />Workspaces and account</Link
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
