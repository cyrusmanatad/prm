<script setup lang="ts">
import { computed, ref } from 'vue';
import { ClipboardList, ContactRound, FileText } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import AccountMenu, { type AccountMenuLink } from '../../components/AccountMenu.vue';

const props = defineProps<{
    accountName: string;
    accountEmail?: string;
    current: 'leads' | 'media-kit';
    fixture?: boolean;
    searchValue?: string;
}>();
const fixture = computed(() => import.meta.env.DEV && props.fixture === true);
const initials = computed(() =>
    fixture.value
        ? 'BS'
        : props.accountName
              .split(' ')
              .map((part) => part[0])
              .slice(0, 2)
              .join(''),
);
const search = ref(props.searchValue ?? '');
const accountLinks: AccountMenuLink[] = [
    { href: '/partner/contacts', label: 'Contacts and reminders', icon: ContactRound },
    { href: '/partner/outreach-templates', label: 'Outreach templates', icon: FileText },
    { href: '/partner/onboarding', label: 'Onboarding requests', icon: ClipboardList },
];
function searchLeads(): void {
    if (!fixture.value) router.get('/partner/leads', { q: search.value });
}
</script>

<template>
    <div
        class="portal-ui min-h-screen bg-cream font-sans leading-[normal] text-ink"
        @click="fixture && $event.preventDefault()"
    >
        <a class="wd-skip-link" href="#main-content">Skip to main content</a>
        <header class="bg-brand text-white">
            <div class="mx-auto flex max-w-[1408px] flex-wrap items-center gap-6 px-6 py-3.5">
                <div class="flex items-center gap-2.5">
                    <svg viewBox="0 0 128 120" width="36" height="34" role="img" aria-label="WeavDash logo">
                        <circle cx="60" cy="60" r="52" fill="#E3C79A"></circle>
                        <circle cx="60" cy="60" r="45" fill="#8E1650"></circle>
                        <path
                            d="M72.0 60.0 L106.0 60.0 M71.6 63.1 L104.4 71.9 M70.4 66.0 L99.8 83.0 M68.5 68.5 L92.5 92.5 M66.0 70.4 L83.0 99.8 M63.1 71.6 L71.9 104.4 M60.0 72.0 L60.0 106.0 M56.9 71.6 L48.1 104.4 M54.0 70.4 L37.0 99.8 M51.5 68.5 L27.5 92.5 M49.6 66.0 L20.2 83.0 M48.4 63.1 L15.6 71.9 M48.0 60.0 L14.0 60.0 M48.4 56.9 L15.6 48.1 M49.6 54.0 L20.2 37.0 M51.5 51.5 L27.5 27.5 M54.0 49.6 L37.0 20.2 M56.9 48.4 L48.1 15.6 M60.0 48.0 L60.0 14.0 M63.1 48.4 L71.9 15.6 M66.0 49.6 L83.0 20.2 M68.5 51.5 L92.5 27.5 M70.4 54.0 L99.8 37.0 M71.6 56.9 L104.4 48.1"
                            stroke="#FBF7EF"
                            stroke-width="2.4"
                            stroke-linecap="round"
                        ></path>
                        <path
                            d="M60.0 58.0 L60.3 57.9 L60.6 57.8 L60.9 57.8 L61.2 57.9 L61.5 57.9 L61.8 58.1 L62.1 58.2 L62.4 58.4 L62.7 58.7 L63.0 59.0 L63.2 59.4 L63.3 59.8 L63.5 60.2 L63.5 60.7 L63.5 61.1 L63.4 61.6 L63.3 62.1 L63.1 62.6 L62.8 63.0 L62.5 63.4 L62.1 63.8 L61.6 64.2 L61.1 64.4 L60.6 64.7 L60.0 64.8 L59.4 64.9 L58.7 64.9 L58.1 64.8 L57.5 64.6 L56.8 64.3 L56.2 64.0 L55.7 63.6 L55.2 63.1 L54.7 62.5 L54.4 61.8 L54.1 61.1 L53.8 60.4 L53.7 59.6 L53.7 58.8 L53.8 58.0 L54.0 57.2 L54.3 56.4 L54.7 55.6 L55.2 54.9 L55.8 54.3 L56.5 53.7 L57.3 53.2 L58.2 52.8 L59.1 52.5 L60.0 52.4 L61.0 52.3 L62.0 52.4 L62.9 52.6 L63.9 52.9 L64.8 53.4 L65.7 53.9 L66.5 54.6 L67.2 55.4 L67.8 56.3 L68.3 57.3 L68.7 58.3 L69.0 59.4 L69.1 60.6 L69.0 61.7 L68.9 62.9 L68.5 64.0 L68.1 65.1 L67.4 66.2 L66.7 67.1 L65.8 68.0 L64.8 68.8 L63.7 69.4 L62.5 69.9 L61.3 70.2 L60.0 70.4 L58.7 70.5 L57.3 70.3 L56.0 70.0 L54.8 69.5 L53.5 68.9 L52.4 68.1 L51.4 67.2 L50.4 66.1 L49.6 64.9 L49.0 63.6 L48.5 62.2 L48.2 60.7 L48.1 59.3 L48.2 57.7 L48.5 56.3 L48.9 54.8 L49.6 53.4 L50.4 52.1 L51.4 50.8 L52.5 49.7 L53.8 48.8 L55.2 48.0 L56.8 47.4 L58.4 47.0 L60.0 46.8 L61.7 46.7 L63.4 46.9 L65.0 47.4 L66.6 48.0 L68.1 48.8 L69.5 49.8 L70.8 51.1 L71.9 52.4 L72.9 53.9 L73.7 55.6 L74.2 57.3 L74.6 59.1 L74.7 60.9 L74.6 62.8 L74.2 64.6 L73.6 66.4 L72.8 68.1 L71.8 69.7 L70.5 71.2 L69.1 72.5 L67.5 73.7 L65.8 74.6 L63.9 75.3 L62.0 75.8 L60.0 76.1 L58.0 76.0 L55.9 75.8 L54.0 75.2 L52.0 74.5 L50.2 73.4 L48.5 72.2 L47.0 70.7 L45.7 69.1 L44.6 67.3 L43.7 65.3 L43.0 63.2 L42.6 61.1 L42.5 58.9 L42.7 56.7 L43.1 54.5 L43.8 52.4 L44.8 50.4 L46.1 48.5 L47.5 46.7 L49.2 45.2 L51.1 43.9 L53.2 42.8 L55.4 41.9 L57.6 41.4 L60.0 41.1 L62.4 41.2 L64.7 41.5 L67.1 42.1 L69.3 43.1 L71.4 44.3 L73.4 45.7 L75.2 47.5 L76.7 49.4 L78.0 51.5 L79.0 53.8"
                            fill="none"
                            stroke="#8FD0B8"
                            stroke-width="5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></path>
                        <path
                            d="M79 53.8 C88 45 100 33 121 20"
                            fill="none"
                            stroke="#8FD0B8"
                            stroke-width="7"
                            stroke-linecap="round"
                        ></path>
                    </svg>
                    <span class="font-display text-[20px] font-bold">WeavDash</span>
                    <span class="rounded-full bg-white/12 px-2 py-[3px] text-[12px]">Partner</span>
                </div>
                <nav aria-label="Main" class="flex flex-auto flex-wrap gap-1">
                    <a
                        href="/partner/leads"
                        :aria-current="current === 'leads' ? 'page' : undefined"
                        :class="
                            current === 'leads'
                                ? 'rounded-lg bg-white/18 px-3 py-2 text-[14px] font-semibold text-white'
                                : 'px-3 py-2 text-[14px] text-lilac'
                        "
                        >My leads</a
                    >
                    <span title="Unavailable in this milestone" class="px-3 py-2 text-[14px] text-lilac">Inbox</span>
                    <span title="Unavailable in this milestone" class="px-3 py-2 text-[14px] text-lilac"
                        >Campaigns</span
                    >
                    <a
                        href="/partner/media-kit"
                        :aria-current="current === 'media-kit' ? 'page' : undefined"
                        :class="
                            current === 'media-kit'
                                ? 'rounded-lg bg-white/18 px-3 py-2 text-[14px] font-semibold text-white'
                                : 'px-3 py-2 text-[14px] text-lilac'
                        "
                        >Media kit</a
                    >
                    <span title="Unavailable in this milestone" class="px-3 py-2 text-[14px] text-lilac">Earnings</span>
                    <span title="Unavailable in this milestone" class="px-3 py-2 text-[14px] text-lilac"
                        >Autopilot</span
                    >
                </nav>
                <div class="flex items-center gap-3">
                    <label for="q" class="sr-only">Search leads</label>
                    <input
                        id="q"
                        v-model="search"
                        type="search"
                        placeholder="Search brands, contacts…"
                        class="w-[220px] rounded-lg bg-white px-3 py-[9px] text-[14px] text-ink placeholder:text-muted"
                        @keydown.enter="searchLeads"
                    />
                    <AccountMenu
                        :account-name="accountName"
                        :account-email="accountEmail"
                        :initials="initials"
                        :fixture="fixture"
                        :links="accountLinks"
                        @logout="router.post('/logout')"
                    />
                </div>
            </div>
        </header>
        <slot />
    </div>
</template>
