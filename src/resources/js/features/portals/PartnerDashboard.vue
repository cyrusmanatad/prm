<script setup lang="ts">
import { computed, ref } from 'vue';
import { Settings, ContactRound, FileText, ClipboardList, LogOut } from '@lucide/vue';
import { Link, router } from '@inertiajs/vue3';
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuPortal,
    DropdownMenuContent,
    DropdownMenuItem,
} from 'reka-ui';
import LeadManager from '../pipeline/LeadManager.vue';
import { money } from '../pipeline/money';
import type { PortalData } from './types';
const props = defineProps<{ data: PortalData; fixture?: boolean }>();
// Synthetic reference content cannot be enabled in a production build.
const fixture = import.meta.env.DEV && props.fixture === true;
const initials = computed(() =>
    fixture
        ? 'BS'
        : props.data.account.name
              .split(' ')
              .map((p) => p[0])
              .slice(0, 2)
              .join(''),
);
defineEmits<{ invite: []; remove: [id: string] }>();
const search = ref(props.data.pipeline?.filters.q ?? '');
const leadManager = ref<InstanceType<typeof LeadManager> | null>(null);
const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
const stages = [
    { value: 'lead', label: 'Lead' },
    { value: 'pitched', label: 'Pitched' },
    { value: 'negotiating', label: 'Negotiating' },
    { value: 'onboarded', label: 'Onboarded' },
    { value: 'active', label: 'Active' },
];
function open(path: string): void {
    if (!fixture) router.get(path);
}
function leadHref(id: string): string {
    const params = new URLSearchParams(location.search);
    params.delete('create');
    params.set('entry', id);
    return `/partner/leads?${params}`;
}
function searchLeads(): void {
    if (!fixture) router.get('/partner/leads', { q: search.value });
}
const summary = computed(() => [
    {
        label: 'Open pipeline',
        value: props.data.pipeline?.totals.map((t) => money(t.amount_minor, t.currency)).join(' · ') || 'No leads',
        hint: 'Stored estimates, separated by currency',
    },
    ...['Expected this month', 'Commissions earned', 'Reply rate'].map((label) => ({
        label,
        value: 'Unavailable',
        hint: 'Not yet measured',
    })),
]);
</script>
<template>
    <div
        class="portal-ui min-h-screen bg-cream font-sans leading-[normal] text-ink"
        @click="fixture && $event.preventDefault()"
    >
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
                        aria-current="page"
                        class="rounded-lg bg-white/18 px-3 py-2 text-[14px] font-semibold text-white"
                        >My leads</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-lilac"
                        >Inbox</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-lilac"
                        >Campaigns</a
                    >
                    <a href="/partner/media-kit" class="px-3 py-2 text-[14px] text-lilac">Media kit</a>
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-lilac"
                        >Earnings</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-lilac"
                        >Autopilot</a
                    >
                </nav>
                <div class="flex items-center gap-3">
                    <label for="q" class="sr-only">Search leads</label>
                    <input
                        id="q"
                        v-model="search"
                        @keydown.enter="searchLeads"
                        type="search"
                        placeholder="Search brands, contacts…"
                        class="w-[220px] rounded-lg bg-white px-3 py-[9px] text-[14px] text-black placeholder:text-[darkgray]"
                    />
                    <DropdownMenuRoot>
                        <DropdownMenuTrigger as-child
                            ><button
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sand text-[13px] font-bold text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                            >
                                <span>{{ initials }}</span
                                ><span class="sr-only">Account menu</span>
                            </button></DropdownMenuTrigger
                        >
                        <DropdownMenuPortal>
                            <DropdownMenuContent align="end" :side-offset="8" class="partner-account-menu">
                                <DropdownMenuItem as-child
                                    ><Link href="/workspaces"
                                        ><Settings :size="18" aria-hidden="true" class="shrink-0" />Switch workspace /
                                        account security</Link
                                    ></DropdownMenuItem
                                >
                                <DropdownMenuItem as-child
                                    ><Link href="/partner/contacts"
                                        ><ContactRound :size="18" aria-hidden="true" class="shrink-0" />Contacts and
                                        reminders</Link
                                    ></DropdownMenuItem
                                >
                                <DropdownMenuItem as-child
                                    ><Link href="/partner/outreach-templates"
                                        ><FileText :size="18" aria-hidden="true" class="shrink-0" />Outreach
                                        templates</Link
                                    ></DropdownMenuItem
                                >
                                <DropdownMenuItem as-child
                                    ><Link href="/partner/onboarding"
                                        ><ClipboardList :size="18" aria-hidden="true" class="shrink-0" />Onboarding
                                        requests</Link
                                    ></DropdownMenuItem
                                >
                                <DropdownMenuItem @select="!fixture && router.post('/logout')"
                                    ><LogOut :size="18" aria-hidden="true" class="shrink-0" />Sign out</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                </div>
            </div>
        </header>

        <main class="mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="mb-1 text-[14px] text-muted">{{ fixture ? 'Saturday, 17 October' : today }}</p>
                    <h1 class="font-display text-[34px] leading-[1.1] font-bold">
                        {{ fixture ? 'Magandang umaga, Bea' : `Magandang umaga, ${data.account.name.split(' ')[0]}` }}
                    </h1>
                    <p class="mt-2 text-[15px] text-ink-soft">
                        <template v-if="fixture"
                            >You have <strong>4 actions</strong> today. Autopilot handled <strong>14 tasks</strong> for
                            you this week.</template
                        ><template v-else>Your private leads and explicit brand partnerships, in one place.</template>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <button
                        type="button"
                        @click="open('/partner/media-kit')"
                        class="rounded-[10px] border border-line-strong bg-white px-4 py-[11px] text-[14px] font-semibold text-ink"
                    >
                        Share my lead link
                    </button>
                    <button
                        type="button"
                        v-if="fixture || data.pipeline?.canWrite"
                        @click="!fixture && leadManager?.openCreate()"
                        class="rounded-[10px] bg-brand px-4 py-[11px] text-[14px] font-semibold text-white"
                    >
                        + Add lead
                    </button>
                </div>
            </div>

            <section aria-label="Summary" class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
                <template v-if="fixture">
                    <div class="rounded-[14px] border border-line bg-white px-5 py-[18px]">
                        <div class="text-[13px] font-semibold text-muted">Open pipeline</div>
                        <div class="mt-1.5 font-display text-[28px] font-bold">₱186,500</div>
                        <div class="mt-1 text-[13px] font-semibold text-forest">12 brands in play</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-5 py-[18px]">
                        <div class="text-[13px] font-semibold text-muted">Expected this month</div>
                        <div class="mt-1.5 font-display text-[28px] font-bold">₱72,000</div>
                        <div class="mt-1 text-[13px] font-semibold text-forest">Weighted by win chance</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-5 py-[18px]">
                        <div class="text-[13px] font-semibold text-muted">Commissions earned</div>
                        <div class="mt-1.5 font-display text-[28px] font-bold">₱18,240</div>
                        <div class="mt-1 text-[13px] font-semibold text-forest">+₱4,200 this week</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-5 py-[18px]">
                        <div class="text-[13px] font-semibold text-muted">Reply rate</div>
                        <div class="mt-1.5 font-display text-[28px] font-bold">41%</div>
                        <div class="mt-1 text-[13px] font-semibold text-forest">Up from 33% last month</div>
                    </div> </template
                ><template v-else
                    ><div
                        v-for="card in summary"
                        :key="card.label"
                        class="rounded-[14px] border border-line bg-white px-5 py-[18px]"
                    >
                        <div class="text-[13px] font-semibold text-muted">{{ card.label }}</div>
                        <div class="mt-1.5 font-display text-[28px] font-bold">{{ card.value }}</div>
                        <div class="mt-1 text-[13px] text-muted">{{ card.hint }}</div>
                    </div></template
                >
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-6">
                    <section class="rounded-2xl border border-line bg-white p-5">
                        <template v-if="fixture">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h2 class="font-display text-[18px] font-bold">Today's next best actions</h2>
                                <span class="text-[12px] text-muted"
                                    >Ranked by likelihood to close · tap “Why” to see the reasoning</span
                                >
                            </div>
                            <div class="flex flex-col">
                                <div class="flex flex-wrap items-center gap-3.5 border-t border-line-soft py-3.5">
                                    <div
                                        class="flex size-10 flex-none items-center justify-center rounded-[10px] bg-blush text-[14px] font-bold text-brand"
                                    >
                                        LS
                                    </div>
                                    <div class="min-w-0 flex-[1_1_260px]">
                                        <div class="text-[15px] font-bold">Send rate card follow-up</div>
                                        <div class="mt-0.5 text-[13px] text-muted">
                                            Luntian Skincare · Negotiating · ₱45,000
                                        </div>
                                        <div
                                            class="mt-1.5 inline-block rounded-lg bg-cream px-2.5 py-1.5 text-[12px] text-ink-soft"
                                        >
                                            Why: Opened your media kit 3× since Tuesday; they usually reply on weekday
                                            mornings via email
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] border border-line-strong bg-white px-3 py-2.5 text-[13px] font-semibold text-ink"
                                        >
                                            Snooze
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] bg-mint px-3.5 py-2.5 text-[13px] font-bold text-ink"
                                        >
                                            Review draft
                                        </button>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3.5 border-t border-line-soft py-3.5">
                                    <div
                                        class="flex size-10 flex-none items-center justify-center rounded-[10px] bg-blush text-[14px] font-bold text-brand"
                                    >
                                        KK
                                    </div>
                                    <div class="min-w-0 flex-[1_1_260px]">
                                        <div class="text-[15px] font-bold">Confirm 11.11 deliverables</div>
                                        <div class="mt-0.5 text-[13px] text-muted">
                                            Kape Kultura · Onboarded · ₱28,000
                                        </div>
                                        <div
                                            class="mt-1.5 inline-block rounded-lg bg-cream px-2.5 py-1.5 text-[12px] text-ink-soft"
                                        >
                                            Why: Brief submitted through your onboarding link; 2 of 3 deliverables still
                                            need dates
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] border border-line-strong bg-white px-3 py-2.5 text-[13px] font-semibold text-ink"
                                        >
                                            Snooze
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] bg-mint px-3.5 py-2.5 text-[13px] font-bold text-ink"
                                        >
                                            Set dates
                                        </button>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3.5 border-t border-line-soft py-3.5">
                                    <div
                                        class="flex size-10 flex-none items-center justify-center rounded-[10px] bg-blush text-[14px] font-bold text-brand"
                                    >
                                        HH
                                    </div>
                                    <div class="min-w-0 flex-[1_1_260px]">
                                        <div class="text-[15px] font-bold">Re-pitch for Christmas gifting</div>
                                        <div class="mt-0.5 text-[13px] text-muted">
                                            Habi Home · Repeat client · ₱60,000
                                        </div>
                                        <div
                                            class="mt-1.5 inline-block rounded-lg bg-cream px-2.5 py-1.5 text-[12px] text-ink-soft"
                                        >
                                            Why: Last campaign hit 2.4× their ROAS target; they booked holiday creators
                                            in early Nov last year
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] border border-line-strong bg-white px-3 py-2.5 text-[13px] font-semibold text-ink"
                                        >
                                            Snooze
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] bg-mint px-3.5 py-2.5 text-[13px] font-bold text-ink"
                                        >
                                            Review pitch
                                        </button>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3.5 border-t border-line-soft py-3.5">
                                    <div
                                        class="flex size-10 flex-none items-center justify-center rounded-[10px] bg-blush text-[14px] font-bold text-brand"
                                    >
                                        AS
                                    </div>
                                    <div class="min-w-0 flex-[1_1_260px]">
                                        <div class="text-[15px] font-bold">Reply to inbound lead</div>
                                        <div class="mt-0.5 text-[13px] text-muted">
                                            Alon Swimwear · Lead · ₱18,000 est.
                                        </div>
                                        <div
                                            class="mt-1.5 inline-block rounded-lg bg-cream px-2.5 py-1.5 text-[12px] text-ink-soft"
                                        >
                                            Why: Came in through your lead link 2 hrs ago; fit score 86 (audience
                                            overlap, beach &amp; travel niche)
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] border border-line-strong bg-white px-3 py-2.5 text-[13px] font-semibold text-ink"
                                        >
                                            Snooze
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-11 rounded-[9px] bg-mint px-3.5 py-2.5 text-[13px] font-bold text-ink"
                                        >
                                            Reply
                                        </button>
                                    </div>
                                </div>
                            </div> </template
                        ><template v-else
                            ><h2 class="mb-3 font-display text-[18px] font-bold">Today's next best actions</h2>
                            <p class="text-[13px] text-muted">AI ranking and suggested drafts are unavailable.</p>
                            <Link
                                href="/partner/contacts?state=due"
                                class="mt-3 inline-block text-[14px] font-bold underline"
                                >View contacts and due reminders →</Link
                            ></template
                        >
                    </section>

                    <section class="rounded-2xl border border-line bg-white p-5">
                        <template v-if="fixture">
                            <div class="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                                <h2 class="font-display text-[18px] font-bold">My pipeline</h2>
                                <span class="text-[12px] text-muted"
                                    >Drag a card to move stage · stage changes fire automations</span
                                >
                            </div>
                            <div class="overflow-x-auto">
                                <div class="grid min-w-[900px] grid-cols-[repeat(5,minmax(170px,1fr))] gap-3">
                                    <div class="flex flex-col gap-2 rounded-xl bg-cream p-2.5">
                                        <div class="flex items-center justify-between px-1 py-0.5">
                                            <span class="text-[13px] font-bold">Lead</span>
                                            <span class="text-[12px] text-muted">4 · ₱52k</span>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Alon Swimwear</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱18k · Lead link</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Fit 86</span
                                                >
                                                <span class="text-[11px] text-muted">Today</span>
                                            </div>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Tala Tea Co.</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱12k · Discovery</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Fit 74</span
                                                >
                                                <span class="text-[11px] text-muted">Mon</span>
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-lg border border-dashed border-line-strong px-2 py-1.5 text-[11px] text-ink-soft"
                                        >
                                            <strong>Auto:</strong> New lead → fit score + first reply draft
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2 rounded-xl bg-cream p-2.5">
                                        <div class="flex items-center justify-between px-1 py-0.5">
                                            <span class="text-[13px] font-bold">Pitched</span>
                                            <span class="text-[12px] text-muted">3 · ₱38k</span>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Sari Snacks</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱15k · Cold pitch</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Opened 2×</span
                                                >
                                                <span class="text-[11px] text-muted">Tue</span>
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-lg border border-dashed border-line-strong px-2 py-1.5 text-[11px] text-ink-soft"
                                        >
                                            <strong>Auto:</strong> 3 days no reply → follow-up draft
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2 rounded-xl bg-cream p-2.5">
                                        <div class="flex items-center justify-between px-1 py-0.5">
                                            <span class="text-[13px] font-bold">Negotiating</span>
                                            <span class="text-[12px] text-muted">2 · ₱63k</span>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Luntian Skincare</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱45k · Referral</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Hot</span
                                                >
                                                <span class="text-[11px] text-muted">Today</span>
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-lg border border-dashed border-line-strong px-2 py-1.5 text-[11px] text-ink-soft"
                                        >
                                            <strong>Auto:</strong> Rate range suggested from comparables
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2 rounded-xl bg-cream p-2.5">
                                        <div class="flex items-center justify-between px-1 py-0.5">
                                            <span class="text-[13px] font-bold">Onboarded</span>
                                            <span class="text-[12px] text-muted">2 · ₱36k</span>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Kape Kultura</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱28k · Inbound</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Signed</span
                                                >
                                                <span class="text-[11px] text-muted">Today</span>
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-lg border border-dashed border-line-strong px-2 py-1.5 text-[11px] text-ink-soft"
                                        >
                                            <strong>Auto:</strong> Form done → contract sent for e-sign
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2 rounded-xl bg-cream p-2.5">
                                        <div class="flex items-center justify-between px-1 py-0.5">
                                            <span class="text-[13px] font-bold">Active</span>
                                            <span class="text-[12px] text-muted">3 · ₱74k</span>
                                        </div>
                                        <div class="rounded-[10px] border border-line bg-white px-3 py-2.5">
                                            <div class="text-[14px] font-bold">Habi Home</div>
                                            <div class="mt-0.5 text-[12px] text-muted">₱60k · Repeat</div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <span
                                                    class="rounded-full bg-mint-soft px-[7px] py-[3px] text-[11px] font-bold text-mint-ink"
                                                    >Code HABIBEA</span
                                                >
                                                <span class="text-[11px] text-muted">Posting Thu</span>
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-lg border border-dashed border-line-strong px-2 py-1.5 text-[11px] text-ink-soft"
                                        >
                                            <strong>Auto:</strong> Approved → invoice → payout
                                        </div>
                                    </div>
                                </div>
                            </div> </template
                        ><template v-else
                            ><h2 class="mb-3 font-display text-[18px] font-bold">My pipeline</h2>
                            <p class="mb-3 text-[12px] text-muted">
                                Open a lead to edit, move stage or explicitly start onboarding. Automation is
                                unavailable.
                            </p>
                            <LeadManager
                                v-if="data.pipeline"
                                ref="leadManager"
                                v-bind="data.pipeline"
                                :workspace="data.workspace"
                                :errors="data.errors"
                                :status="data.status"
                                ><div
                                    class="overflow-x-auto"
                                    tabindex="0"
                                    role="region"
                                    aria-label="Five-stage pipeline"
                                >
                                    <div class="grid min-w-[900px] grid-cols-[repeat(5,minmax(170px,1fr))] gap-3">
                                        <div
                                            v-for="stage in stages"
                                            :key="stage.value"
                                            class="flex flex-col gap-2 rounded-xl bg-cream p-2.5"
                                        >
                                            <div class="flex items-center justify-between px-1 py-0.5">
                                                <span class="text-[13px] font-bold">{{ stage.label }}</span
                                                ><span class="text-[12px] text-muted">{{
                                                    data.pipeline?.counts.find((c) => c.stage === stage.value)?.count ??
                                                    0
                                                }}</span>
                                            </div>
                                            <Link
                                                v-for="entry in data.pipeline?.entries.filter(
                                                    (e) => e.stage === stage.value,
                                                )"
                                                :key="entry.id"
                                                :href="leadHref(entry.id)"
                                                class="rounded-[10px] border border-line bg-white px-3 py-2.5"
                                                ><div class="text-[14px] font-bold">{{ entry.brand_name }}</div>
                                                <div class="mt-0.5 text-[12px] text-muted">
                                                    {{ money(entry.value_minor, entry.currency) }}
                                                </div></Link
                                            >
                                            <p
                                                v-if="!data.pipeline?.entries.some((e) => e.stage === stage.value)"
                                                class="text-[12px] text-muted"
                                            >
                                                No leads on this page
                                            </p>
                                        </div>
                                    </div>
                                </div></LeadManager
                            ></template
                        >
                    </section>
                </div>

                <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-6">
                    <section class="rounded-2xl border border-line bg-white p-5">
                        <template v-if="fixture">
                            <h2 class="mb-1 font-display text-[18px] font-bold">Autopilot</h2>
                            <p class="mb-3 text-[13px] text-muted">
                                You stay in control: drafts wait for your OK, everything else runs on its own.
                            </p>
                            <div class="flex flex-col">
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Smart follow-ups</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            No reply in 3 days → draft a follow-up for the best day and channel
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">
                                            6 drafts approved this week
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="true"
                                        aria-label="Turn off Smart follow-ups"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Send onboarding link on “Won”</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            Brand gets your branded form for TIN, brief, guidelines and shipping
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">
                                            2 brands onboarded, 0 chat chasing
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="true"
                                        aria-label="Turn off Send onboarding link on “Won”"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Invoice when deliverable is approved</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            Sequential invoice with your TIN and withholding fields
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">₱31,500 invoiced</div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="true"
                                        aria-label="Turn off Invoice when deliverable is approved"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Auto-payout to GCash</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            Approved commissions paid every Friday
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">
                                            Next payout: ₱18,240 on 23 Oct
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="true"
                                        aria-label="Turn off Auto-payout to GCash"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Seasonal re-pitch</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            Suggest re-pitches 3 weeks before 11.11, 12.12, Christmas
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">
                                            5 brands eligible for 12.12
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="false"
                                        aria-label="Turn on Seasonal re-pitch"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-3 border-t border-line-soft py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[14px] font-bold">Monday earnings digest</div>
                                        <div class="mt-0.5 text-[12px] text-muted">
                                            Forecast, overdue invoices and 2307s to collect, via email or Messenger
                                        </div>
                                        <div class="mt-1 text-[11px] font-semibold text-ink-soft">
                                            Sent every Monday 8:00 AM
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        aria-pressed="true"
                                        aria-label="Turn off Monday earnings digest"
                                        class="flex h-7 w-[46px] flex-none items-center justify-start rounded-full bg-switch-off p-[3px] aria-pressed:justify-end aria-pressed:bg-forest"
                                    >
                                        <span class="block size-[22px] rounded-full bg-white"></span>
                                    </button>
                                </div>
                            </div> </template
                        ><template v-else
                            ><h2 class="mb-3 font-display text-[18px] font-bold">Autopilot</h2>
                            <p class="text-[13px] text-muted">
                                Automation is unavailable. Start onboarding explicitly from a lead; only non-sensitive
                                business details are collected.
                            </p>
                            <div
                                v-for="label in [
                                    'Smart follow-ups',
                                    'Onboarding automation',
                                    'Invoice automation',
                                    'Automatic payouts',
                                    'Seasonal re-pitch',
                                    'Earnings digest',
                                ]"
                                :key="label"
                                class="flex items-center gap-3 border-t border-line-soft py-3"
                            >
                                <span class="flex-1 text-[14px] font-bold">{{ label }}</span
                                ><button
                                    disabled
                                    type="button"
                                    :aria-label="`${label}: unavailable`"
                                    aria-pressed="false"
                                    title="Unavailable in this milestone"
                                    class="flex h-7 w-[46px] items-center rounded-full bg-switch-off p-[3px]"
                                >
                                    <span class="block size-[22px] rounded-full bg-white"></span>
                                </button></div
                        ></template>
                    </section>

                    <section class="rounded-2xl bg-ink p-5 text-white">
                        <template v-if="fixture">
                            <h2 class="mb-1 font-display text-[18px] font-bold">From your brand partners</h2>
                            <p class="mb-3.5 text-[13px] text-lilac">Perks and goals your brands set for you</p>
                            <div class="flex flex-col gap-3.5">
                                <div class="rounded-xl bg-white/7 p-3.5">
                                    <div class="flex justify-between gap-2">
                                        <span class="text-[14px] font-bold">Luntian Skincare</span>
                                        <span class="text-[12px] font-bold text-mint">Silver → Gold</span>
                                    </div>
                                    <div class="mt-1 text-[13px] text-lilac-soft">
                                        2 more sales to unlock 15% commission
                                    </div>
                                    <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/15">
                                        <div class="h-full w-[80%] rounded-full bg-mint"></div>
                                    </div>
                                    <div class="mt-1.5 text-[12px] text-lilac">8 of 10 sales this month</div>
                                </div>
                                <div class="rounded-xl bg-white/7 p-3.5">
                                    <div class="flex justify-between gap-2">
                                        <span class="text-[14px] font-bold">Habi Home</span>
                                        <span class="text-[12px] font-bold text-mint">11.11 Sprint</span>
                                    </div>
                                    <div class="mt-1 text-[13px] text-lilac-soft">+5% bonus on orders 9–11 Nov</div>
                                    <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/15">
                                        <div class="h-full w-[35%] rounded-full bg-mint"></div>
                                    </div>
                                    <div class="mt-1.5 text-[12px] text-lilac">Brief and assets ready in Campaigns</div>
                                </div>
                                <div class="rounded-xl bg-white/7 p-3.5">
                                    <div class="flex justify-between gap-2">
                                        <span class="text-[14px] font-bold">Kape Kultura</span>
                                        <span class="text-[12px] font-bold text-mint">New partner</span>
                                    </div>
                                    <div class="mt-1 text-[13px] text-lilac-soft">
                                        Free product kit after first 3 orders
                                    </div>
                                    <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/15">
                                        <div class="h-full w-[33%] rounded-full bg-mint"></div>
                                    </div>
                                    <div class="mt-1.5 text-[12px] text-lilac">1 of 3 orders</div>
                                </div>
                            </div>
                            <a href="#" class="mt-3.5 inline-block text-[14px] font-bold text-mint underline"
                                >Ask a brand for support →</a
                            > </template
                        ><template v-else
                            ><h2 class="mb-3 font-display text-[18px] font-bold">From your brand partners</h2>
                            <div
                                v-for="row in data.relationships"
                                :key="row.id"
                                class="mt-3 rounded-xl bg-white/7 p-3.5"
                            >
                                <strong>{{ row.brand_name }}</strong>
                                <p class="text-[13px] text-lilac">
                                    Active partnership · tiers and commissions unavailable
                                </p>
                                <button
                                    v-if="data.canManage"
                                    class="mt-2 text-[13px] underline"
                                    type="button"
                                    @click="$emit('remove', row.id)"
                                >
                                    End partnership
                                </button>
                            </div>
                            <p v-if="!data.relationships.length" class="text-[13px] text-lilac">
                                No active partnerships. Accept a brand invitation to connect.
                            </p></template
                        >
                    </section>
                </aside>
            </div>
        </main>
    </div>
</template>

<style>
.partner-account-menu {
    z-index: 50;
    max-width: calc(100vw - 32px);
    padding: 6px;
    border: 1px solid #e7dfd5;
    border-radius: 12px;
    background: #fff;
    color: #25212b;
    box-shadow: 0 8px 24px rgb(0 0 0 / 14%);
    font-family: inherit;
    font-size: 14px;
}
.partner-account-menu [role='menuitem'] {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 6px;
    cursor: pointer;
    outline: none;
    text-decoration: none;
}
.partner-account-menu [data-highlighted] {
    background: #f6edf1;
    color: #8e1650;
}
</style>
