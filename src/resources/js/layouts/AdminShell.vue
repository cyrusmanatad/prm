<script setup lang="ts">
import '../../css/records.css';
import '../../css/admin.css';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import AccountMenu from '../components/AccountMenu.vue';
import BrandLogo from '../components/BrandLogo.vue';
import DialogClose from '../components/DialogClose.vue';
import { containDialogFocus } from '../composables/useDialogFocus';
import { activeHref, operatorLabel, visibleGroups, type AdminContext } from '../features/admin/navigation';

/** `compact` uses the records-page header (28px title, no eyebrow) shared with the compact workspace pages. */
defineProps<{ title: string; description?: string; eyebrow?: string; compact?: boolean }>();

const page = usePage<{ admin?: AdminContext; account?: { name: string; email: string } | null }>();
const context = computed(() => page.props.admin);
const account = computed(() => page.props.account);
const groups = computed(() => visibleGroups(context.value));
const current = computed(() =>
    activeHref(
        page.url,
        groups.value.flatMap((group) => group.links),
    ),
);
const drawer = ref<HTMLDialogElement>();
function open() {
    drawer.value?.showModal();
}
function close() {
    drawer.value?.close();
}
// Following a link inside the drawer must not leave a modal over the new page.
watch(() => page.url, close);
</script>

<template>
    <Head :title="title" />
    <div class="wd-shell wd-admin">
        <a class="wd-skip-link" href="#main-content">Skip to main content</a>
        <header class="adm-header">
            <div class="adm-header-inner">
                <button
                    v-if="groups.length"
                    type="button"
                    class="adm-menu-trigger"
                    aria-haspopup="dialog"
                    @click="open"
                >
                    <Menu :size="18" aria-hidden="true" focusable="false" />Menu
                </button>
                <Link href="/admin" class="adm-brand"><BrandLogo /><span class="wd-wordmark">WeavDash</span></Link>
                <div class="adm-labels">
                    <span class="adm-label">Administration</span>
                    <span v-if="context" class="adm-label adm-label-environment"
                        ><span class="wd-sr-only">Environment: </span>{{ context.environment }}</span
                    >
                </div>
                <div class="adm-header-actions">
                    <span v-if="context" class="adm-operator">{{ operatorLabel(context) }}</span>
                    <AccountMenu
                        v-if="account"
                        :account-name="account.name"
                        :account-email="account.email"
                        @logout="router.post('/logout')"
                    />
                </div>
            </div>
        </header>
        <div class="adm-body">
            <nav v-if="groups.length" class="adm-sidebar adm-nav" aria-label="Administration">
                <div v-for="group in groups" :key="group.label" class="adm-nav-group">
                    <h2 class="adm-nav-heading">{{ group.label }}</h2>
                    <ul>
                        <li v-for="link in group.links" :key="link.href">
                            <Link
                                :href="link.href"
                                v-bind="{ 'aria-current': current === link.href ? 'page' : undefined }"
                                ><component :is="link.icon" :size="18" aria-hidden="true" focusable="false" />{{
                                    link.label
                                }}</Link
                            >
                        </li>
                    </ul>
                </div>
            </nav>
            <main id="main-content" class="adm-main">
                <div class="adm-content" :class="{ 'is-compact': compact }">
                    <slot name="back" />
                    <header v-if="compact" class="rec-header">
                        <div>
                            <h1>{{ title }}</h1>
                            <p v-if="description || $slots.description">
                                <slot name="description">{{ description }}</slot>
                            </p>
                        </div>
                        <slot name="actions" />
                    </header>
                    <header v-else class="adm-page-heading">
                        <div>
                            <span class="wd-eyebrow">{{ eyebrow ?? 'Platform administration' }}</span>
                            <h1>{{ title }}</h1>
                            <p v-if="description" class="wd-description">{{ description }}</p>
                        </div>
                        <slot name="actions" />
                    </header>
                    <slot />
                </div>
            </main>
        </div>
        <dialog
            ref="drawer"
            class="adm-drawer"
            aria-labelledby="adm-drawer-title"
            @keydown="containDialogFocus($event, drawer)"
            @click.self="close"
        >
            <div class="adm-drawer-header">
                <h2 id="adm-drawer-title">Administration menu</h2>
                <DialogClose label="Close menu" @click="close" />
            </div>
            <nav class="adm-nav" aria-label="Administration menu">
                <div v-for="group in groups" :key="group.label" class="adm-nav-group">
                    <h3 class="adm-nav-heading">{{ group.label }}</h3>
                    <ul>
                        <li v-for="link in group.links" :key="link.href">
                            <Link
                                :href="link.href"
                                v-bind="{ 'aria-current': current === link.href ? 'page' : undefined }"
                                @click="close"
                                ><component :is="link.icon" :size="18" aria-hidden="true" focusable="false" />{{
                                    link.label
                                }}</Link
                            >
                        </li>
                    </ul>
                </div>
            </nav>
        </dialog>
    </div>
</template>
