<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Settings, LogOut } from '@lucide/vue';
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuPortal,
    DropdownMenuContent,
    DropdownMenuItem,
} from 'reka-ui';
import type { PortalData } from './types';
const props = defineProps<{ data: PortalData; fixture?: boolean }>();
// Synthetic reference content cannot be enabled in a production build.
const fixture = import.meta.env.DEV && props.fixture === true;
const initials = computed(() =>
    props.data.account.name
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join(''),
);
defineEmits<{ invite: []; remove: [id: string] }>();
const summary = computed(() => [
    {
        label: 'Active partners',
        value: String(props.data.relationships.length),
        hint: 'Explicitly accepted partnerships',
    },
    ...['Partner-sourced revenue', 'Leads registered', 'Days to first sale', 'Payouts due Friday'].map((label) => ({
        label,
        value: 'Unavailable',
        hint: 'Not yet implemented',
    })),
]);
</script>
<template>
    <div
        class="portal-ui min-h-screen bg-cream font-sans leading-[normal] text-ink"
        @click="fixture && $event.preventDefault()"
    >
        <header class="border-b border-line bg-white">
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
                    <span class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                        >Brand · {{ fixture ? 'Habi Home' : data.workspace.name }}</span
                    >
                </div>
                <nav aria-label="Main" class="flex flex-auto flex-wrap gap-1">
                    <a
                        href="/brand"
                        aria-current="page"
                        class="rounded-lg bg-cream px-3 py-2 text-[14px] font-bold hover:text-brand"
                        >Partner hub</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-ink-soft"
                        >Lead registrations</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-ink-soft"
                        >Campaigns</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-ink-soft"
                        >Incentives</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-ink-soft"
                        >Enablement</a
                    >
                    <a
                        role="link"
                        aria-disabled="true"
                        tabindex="0"
                        title="Unavailable in this milestone"
                        class="px-3 py-2 text-[14px] text-ink-soft"
                        >Payouts</a
                    >
                </nav>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        :disabled="!fixture && !data.canManage"
                        @click="$emit('invite')"
                        class="rounded-[10px] bg-brand px-3.5 py-2.5 text-[14px] font-bold text-white"
                    >
                        + Invite partners
                    </button>
                    <DropdownMenuRoot :modal="false">
                        <DropdownMenuTrigger as-child
                            ><button
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sand text-[13px] font-bold text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand"
                            >
                                <span>{{ initials }}</span
                                ><span class="sr-only">Account menu</span>
                            </button></DropdownMenuTrigger
                        >
                        <DropdownMenuPortal>
                            <nav aria-label="Account">
                                <DropdownMenuContent align="end" :side-offset="8" class="brand-account-menu">
                                    <DropdownMenuItem as-child
                                        ><Link href="/workspaces"
                                            ><Settings :size="18" aria-hidden="true" class="shrink-0" />Switch workspace
                                            / account security</Link
                                        ></DropdownMenuItem
                                    >
                                    <DropdownMenuItem @select="!fixture && router.post('/logout')"
                                        ><LogOut :size="18" aria-hidden="true" class="shrink-0" />Sign
                                        out</DropdownMenuItem
                                    >
                                </DropdownMenuContent>
                            </nav>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                </div>
            </div>
        </header>

        <main class="mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <h1 class="font-display text-[32px] font-bold">Partner hub</h1>
                <p class="mt-1.5 text-[15px] text-ink-soft">
                    Who's bringing leads, who needs help, and what to do next.
                </p>
            </div>

            <section aria-label="Program summary" class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                <template v-if="fixture">
                    <div class="rounded-[14px] border border-line bg-white px-[18px] py-4">
                        <div class="text-[13px] font-semibold text-muted">Active partners</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">41 / 48</div>
                        <div class="mt-1 text-[12px] text-ink-soft">7 not yet activated</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-[18px] py-4">
                        <div class="text-[13px] font-semibold text-muted">Partner-sourced revenue</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">₱412,600</div>
                        <div class="mt-1 text-[12px] text-ink-soft">Last 30 days · 38% of total</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-[18px] py-4">
                        <div class="text-[13px] font-semibold text-muted">Leads registered</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">73</div>
                        <div class="mt-1 text-[12px] text-ink-soft">58 auto-approved</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-[18px] py-4">
                        <div class="text-[13px] font-semibold text-muted">Days to first sale</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">11</div>
                        <div class="mt-1 text-[12px] text-ink-soft">Median for new partners</div>
                    </div>
                    <div class="rounded-[14px] border border-line bg-white px-[18px] py-4">
                        <div class="text-[13px] font-semibold text-muted">Payouts due Friday</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">₱64,820</div>
                        <div class="mt-1 text-[12px] text-ink-soft">29 partners · GCash, Maya, bank</div>
                    </div> </template
                ><template v-else
                    ><div
                        v-for="card in summary"
                        :key="card.label"
                        class="rounded-[14px] border border-line bg-white px-[18px] py-4"
                    >
                        <div class="text-[13px] font-semibold text-muted">{{ card.label }}</div>
                        <div class="mt-1.5 font-display text-[26px] font-bold">{{ card.value }}</div>
                        <div class="mt-1 text-[13px] text-muted">{{ card.hint }}</div>
                    </div></template
                >
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <section class="min-w-0 flex-[999_1_682px] rounded-2xl border border-line bg-white p-5">
                    <template v-if="fixture">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-display text-[18px] font-bold">Partners</h2>
                            <div class="flex flex-wrap gap-1.5">
                                <span class="rounded-full bg-brand px-2.5 py-[5px] text-[12px] font-bold text-white"
                                    >All 48</span
                                >
                                <span class="rounded-full bg-cream px-2.5 py-[5px] text-[12px] font-semibold"
                                    >Rising 9</span
                                >
                                <span class="rounded-full bg-cream px-2.5 py-[5px] text-[12px] font-semibold"
                                    >At risk 5</span
                                >
                                <span class="rounded-full bg-cream px-2.5 py-[5px] text-[12px] font-semibold"
                                    >Not activated 7</span
                                >
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table
                                class="w-full min-w-[760px] [&_td]:border-b [&_td]:border-line-soft [&_td]:p-3 [&_td]:text-[14px] [&_td]:whitespace-nowrap [&_th]:border-b [&_th]:border-line [&_th]:px-3 [&_th]:py-2.5 [&_th]:text-left [&_th]:text-[12px] [&_th]:font-bold [&_th]:whitespace-nowrap [&_th]:text-muted"
                            >
                                <thead>
                                    <tr>
                                        <th>Partner</th>
                                        <th>Tier</th>
                                        <th>Leads (30d)</th>
                                        <th>Sales</th>
                                        <th>Revenue</th>
                                        <th>Health</th>
                                        <th>Last active</th>
                                        <th><span class="sr-only">Action</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <strong>Bea Santos</strong>
                                            <div class="text-[12px] text-muted">@beasantos · home &amp; lifestyle</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >Gold</span
                                            >
                                        </td>
                                        <td>24</td>
                                        <td>38</td>
                                        <td>₱142,300</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-mint-soft px-2 py-[3px] text-[12px] font-bold text-mint-ink"
                                                >92</span
                                            >
                                        </td>
                                        <td class="text-muted">Today</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Send thank-you
                                            </button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Migs Reyes</strong>
                                            <div class="text-[12px] text-muted">@migsreyes · DIY</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >Silver</span
                                            >
                                        </td>
                                        <td>17</td>
                                        <td>21</td>
                                        <td>₱78,900</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-mint-soft px-2 py-[3px] text-[12px] font-bold text-mint-ink"
                                                >81</span
                                            >
                                        </td>
                                        <td class="text-muted">Yesterday</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Offer Gold path
                                            </button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Ara Villanueva</strong>
                                            <div class="text-[12px] text-muted">@araathome · moms</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >Silver</span
                                            >
                                        </td>
                                        <td>9</td>
                                        <td>8</td>
                                        <td>₱31,200</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >58</span
                                            >
                                        </td>
                                        <td class="text-muted">9 days ago</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Send 11.11 kit
                                            </button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Carlo Dizon</strong>
                                            <div class="text-[12px] text-muted">@carlodizon · tech &amp; desk</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >Bronze</span
                                            >
                                        </td>
                                        <td>2</td>
                                        <td>1</td>
                                        <td>₱4,100</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-alert-soft px-2 py-[3px] text-[12px] font-bold text-alert"
                                                >34</span
                                            >
                                        </td>
                                        <td class="text-muted">21 days ago</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Book check-in
                                            </button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Nina Cruz</strong>
                                            <div class="text-[12px] text-muted">@ninacooks · food</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >New</span
                                            >
                                        </td>
                                        <td>0</td>
                                        <td>0</td>
                                        <td>₱0</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-alert-soft px-2 py-[3px] text-[12px] font-bold text-alert"
                                                >45</span
                                            >
                                        </td>
                                        <td class="text-muted">Joined 6 days ago</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Finish setup
                                            </button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Paolo Lim</strong>
                                            <div class="text-[12px] text-muted">@paololim · travel</div>
                                        </td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >Bronze</span
                                            >
                                        </td>
                                        <td>6</td>
                                        <td>5</td>
                                        <td>₱17,600</td>
                                        <td>
                                            <span
                                                class="rounded-full bg-blush px-2 py-[3px] text-[12px] font-bold text-brand"
                                                >70</span
                                            >
                                        </td>
                                        <td class="text-muted">3 days ago</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="min-h-9 rounded-[9px] border border-line-strong bg-white px-3 py-1.5 text-[13px] font-bold text-ink data-sent:border-forest data-sent:bg-mint-soft data-sent:text-mint-ink"
                                            >
                                                Share top caption
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div> </template
                    ><template v-else
                        ><h2 class="mb-3 font-display text-[18px] font-bold">Partners</h2>
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            <span class="rounded-full bg-brand px-2.5 py-[5px] text-[12px] font-bold text-white"
                                >All {{ data.relationships.length }}</span
                            ><span class="rounded-full bg-cream px-2.5 py-[5px] text-[12px]">Segments unavailable</span>
                        </div>
                        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Partners table">
                            <table class="w-full min-w-[760px] text-left text-[14px]">
                                <thead>
                                    <tr>
                                        <th class="p-3">Partner</th>
                                        <th class="p-3">Tier</th>
                                        <th class="p-3">Leads (30d)</th>
                                        <th class="p-3">Sales</th>
                                        <th class="p-3">Revenue</th>
                                        <th class="p-3">Health</th>
                                        <th class="p-3">Last active</th>
                                        <th class="p-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in data.relationships" :key="row.id" class="border-t border-line">
                                        <td class="p-3">
                                            {{ row.creator_name }}
                                            <p class="text-[12px] text-muted">Active partnership</p>
                                        </td>
                                        <td v-for="column in 6" :key="column" class="p-3 text-muted">Unavailable</td>
                                        <td class="p-3">
                                            <button
                                                v-if="data.canManage"
                                                type="button"
                                                class="underline"
                                                @click="$emit('remove', row.id)"
                                            >
                                                End partnership
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="!data.relationships.length" class="text-[13px] text-muted">
                            No active partners. Invite a partner to begin.
                        </p></template
                    >
                </section>

                <aside class="min-w-0 flex-[1_1_380px] rounded-2xl bg-ink p-5 text-white">
                    <template v-if="fixture">
                        <h2 class="mb-1 font-display text-[18px] font-bold">Needs your attention</h2>
                        <p class="mb-3.5 text-[13px] text-lilac">AI suggestions with the reasons behind them</p>
                        <div class="flex flex-col gap-3">
                            <div class="rounded-xl bg-white/7 p-3.5">
                                <div class="text-[11px] font-bold tracking-[0.04em] text-mint">AT RISK</div>
                                <div class="mt-1 text-[14px] font-bold">Carlo Dizon has gone quiet</div>
                                <div class="mt-1 text-[12px] text-lilac">
                                    Why: No posts or leads in 21 days; his last 2 drafts were rejected for missing #ad
                                </div>
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] bg-mint px-3 py-2 text-[13px] font-bold text-ink"
                                    >
                                        Send disclosure guide + call
                                    </button>
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] border border-white/30 bg-transparent px-3 py-2 text-[13px] font-semibold text-white"
                                    >
                                        Not now
                                    </button>
                                </div>
                            </div>
                            <div class="rounded-xl bg-white/7 p-3.5">
                                <div class="text-[11px] font-bold tracking-[0.04em] text-mint">READY TO GROW</div>
                                <div class="mt-1 text-[14px] font-bold">Migs Reyes is 4 sales from Gold</div>
                                <div class="mt-1 text-[12px] text-lilac">
                                    Why: Sales up 60% month on month; his audience over-indexes on 11.11 shoppers
                                </div>
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] bg-mint px-3 py-2 text-[13px] font-bold text-ink"
                                    >
                                        Offer early 11.11 code
                                    </button>
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] border border-white/30 bg-transparent px-3 py-2 text-[13px] font-semibold text-white"
                                    >
                                        Not now
                                    </button>
                                </div>
                            </div>
                            <div class="rounded-xl bg-white/7 p-3.5">
                                <div class="text-[11px] font-bold tracking-[0.04em] text-mint">STUCK IN ONBOARDING</div>
                                <div class="mt-1 text-[14px] font-bold">3 partners haven’t set up payouts</div>
                                <div class="mt-1 text-[12px] text-lilac">
                                    Why: Partners without a payout method are 3× less likely to make a first sale
                                </div>
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] bg-mint px-3 py-2 text-[13px] font-bold text-ink"
                                    >
                                        Send setup reminder
                                    </button>
                                    <button
                                        type="button"
                                        class="min-h-10 rounded-[9px] border border-white/30 bg-transparent px-3 py-2 text-[13px] font-semibold text-white"
                                    >
                                        Not now
                                    </button>
                                </div>
                            </div>
                        </div> </template
                    ><template v-else
                        ><h2 class="mb-3 font-display text-[18px] font-bold">Needs your attention</h2>
                        <p class="text-[13px] text-lilac">
                            Health signals and automated recommendations are unavailable.
                        </p></template
                    >
                </aside>
            </div>

            <div class="flex flex-wrap items-start gap-6">
                <section class="min-w-0 flex-[1_1_562px] rounded-2xl border border-line bg-white p-5">
                    <template v-if="fixture">
                        <h2 class="mb-1 font-display text-[18px] font-bold">Partner-registered leads</h2>
                        <p class="mb-3 text-[13px] text-muted">
                            Wholesale, corporate gifting and B2B leads partners bring in. Clean leads auto-approve;
                            conflicts come here.
                        </p>
                        <div class="flex flex-wrap items-center gap-3 border-t border-line-soft py-3">
                            <div class="min-w-0 flex-[1_1_220px]">
                                <div class="text-[14px] font-bold">
                                    Liwanag Realty HR – Christmas gifting (120 boxes)
                                </div>
                                <div class="mt-0.5 text-[12px] text-muted">from Bea Santos · ₱96,000 est.</div>
                                <div class="mt-1 text-[12px] text-ink-soft">No CRM match · no other partner claim</div>
                            </div>
                            <span class="rounded-full bg-mint-soft px-[9px] py-1 text-[12px] font-bold text-mint-ink"
                                >Auto-approved</span
                            >
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-line-soft py-3">
                            <div class="min-w-0 flex-[1_1_220px]">
                                <div class="text-[14px] font-bold">Kanto Café chain – table linens</div>
                                <div class="mt-0.5 text-[12px] text-muted">from Migs Reyes · ₱40,000 est.</div>
                                <div class="mt-1 text-[12px] text-ink-soft">
                                    Also registered by Paolo Lim 4 days ago · first-touch rule: Paolo
                                </div>
                            </div>
                            <span
                                class="rounded-full bg-blush px-[9px] py-1 text-[12px] font-bold text-brand data-[status=approved]:bg-mint-soft data-[status=approved]:text-mint-ink"
                                >Conflict</span
                            >
                            <button
                                type="button"
                                class="min-h-10 rounded-[9px] bg-brand px-3 py-2 text-[13px] font-bold text-white"
                            >
                                Approve &amp; protect 60 days
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-line-soft py-3">
                            <div class="min-w-0 flex-[1_1_220px]">
                                <div class="text-[14px] font-bold">Studio Lumen – staging props</div>
                                <div class="mt-0.5 text-[12px] text-muted">from Ara Villanueva · ₱22,000 est.</div>
                                <div class="mt-1 text-[12px] text-ink-soft">
                                    Existing customer in Shopify (last order 2024) · needs review
                                </div>
                            </div>
                            <span
                                class="rounded-full bg-blush px-[9px] py-1 text-[12px] font-bold text-brand data-[status=approved]:bg-mint-soft data-[status=approved]:text-mint-ink"
                                >Review</span
                            >
                            <button
                                type="button"
                                class="min-h-10 rounded-[9px] bg-brand px-3 py-2 text-[13px] font-bold text-white"
                            >
                                Approve &amp; protect 60 days
                            </button>
                        </div> </template
                    ><template v-else
                        ><h2 class="mb-3 font-display text-[18px] font-bold">Partner-registered leads</h2>
                        <p class="text-[13px] text-muted">
                            Lead registration is unavailable. Creator-private prospects are never shared automatically.
                        </p></template
                    >
                </section>

                <section class="min-w-0 flex-[1_1_462px] rounded-2xl border border-line bg-white p-5">
                    <template v-if="fixture">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="font-display text-[18px] font-bold">Tiers &amp; incentives</h2>
                            <a href="#" class="text-[13px] font-bold underline hover:text-brand">Edit rules</a>
                        </div>
                        <p class="mt-1 mb-3.5 text-[13px] text-muted">
                            Partners see their progress live in their portal.
                        </p>
                        <div class="grid grid-cols-3 gap-2.5">
                            <div class="rounded-xl border border-line p-3">
                                <div class="text-[14px] font-bold">Bronze</div>
                                <div class="mt-1 font-display text-[24px] font-bold">8%</div>
                                <div class="text-[12px] text-muted">Default</div>
                                <div class="mt-1.5 text-[12px] font-semibold text-ink-soft">21 partners</div>
                            </div>
                            <div class="rounded-xl border border-line p-3">
                                <div class="text-[14px] font-bold">Silver</div>
                                <div class="mt-1 font-display text-[24px] font-bold">10%</div>
                                <div class="text-[12px] text-muted">5+ sales / month</div>
                                <div class="mt-1.5 text-[12px] font-semibold text-ink-soft">15 partners</div>
                            </div>
                            <div class="rounded-xl border border-line p-3">
                                <div class="text-[14px] font-bold">Gold</div>
                                <div class="mt-1 font-display text-[24px] font-bold">15%</div>
                                <div class="text-[12px] text-muted">10+ sales / month</div>
                                <div class="mt-1.5 text-[12px] font-semibold text-ink-soft">5 partners</div>
                            </div>
                        </div>
                        <div class="mt-3.5 rounded-xl bg-blush p-3.5">
                            <div class="text-[11px] font-bold tracking-[0.04em] text-brand">LIVE SPRINT</div>
                            <div class="mt-1 text-[15px] font-bold">11.11 Sprint · +5% on orders 9–11 Nov</div>
                            <div class="mt-1 text-[13px] text-ink-soft">
                                31 partners opted in · brief, assets and captions auto-sent · bonus calculated into the
                                commission ledger
                            </div>
                        </div> </template
                    ><template v-else
                        ><h2 class="mb-3 font-display text-[18px] font-bold">Tiers &amp; incentives</h2>
                        <p class="text-[13px] text-muted">
                            Program tiers, incentives and commission rules are unavailable.
                        </p>
                        <button
                            disabled
                            type="button"
                            title="Program rules are unavailable"
                            class="mt-3 rounded-lg border border-line px-3 py-2 text-[13px]"
                        >
                            Edit rules
                        </button></template
                    >
                </section>
            </div>

            <section class="rounded-2xl border border-line bg-white p-5">
                <template v-if="fixture">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-display text-[18px] font-bold">Enablement kit</h2>
                        <span class="text-[12px] text-muted">Usage tells you what actually helps partners sell</span>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3">
                        <div class="rounded-xl border border-line p-3.5">
                            <div class="text-[11px] font-bold tracking-[0.04em] text-mint-ink">BRIEF</div>
                            <div class="mt-1 text-[14px] font-bold">11.11 campaign brief</div>
                            <div class="mt-1.5 text-[12px] text-muted">Opened by 34 partners · 31 opted in</div>
                        </div>
                        <div class="rounded-xl border border-line p-3.5">
                            <div class="text-[11px] font-bold tracking-[0.04em] text-mint-ink">CAPTIONS</div>
                            <div class="mt-1 text-[14px] font-bold">Taglish swipe captions (12)</div>
                            <div class="mt-1.5 text-[12px] text-muted">Top: “Sulit pang-regalo” · used 58×</div>
                        </div>
                        <div class="rounded-xl border border-line p-3.5">
                            <div class="text-[11px] font-bold tracking-[0.04em] text-mint-ink">ASSETS</div>
                            <div class="mt-1 text-[14px] font-bold">Holiday product photos</div>
                            <div class="mt-1.5 text-[12px] text-muted">Downloaded 112× · linked to 41 posts</div>
                        </div>
                        <div class="rounded-xl border border-line p-3.5">
                            <div class="text-[11px] font-bold tracking-[0.04em] text-mint-ink">TRAINING</div>
                            <div class="mt-1 text-[14px] font-bold">How to pitch corporate gifting</div>
                            <div class="mt-1.5 text-[12px] text-muted">
                                9 partners finished · they register 2× more B2B leads
                            </div>
                        </div>
                    </div> </template
                ><template v-else
                    ><h2 class="mb-3 font-display text-[18px] font-bold">Enablement kit</h2>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3">
                        <div
                            v-for="label in ['Brief', 'Captions', 'Assets', 'Training']"
                            :key="label"
                            class="rounded-xl border border-line p-3.5"
                        >
                            <strong class="text-[14px]">{{ label }}</strong>
                            <p class="mt-1.5 text-[12px] text-muted">
                                Unavailable until campaign enablement is implemented.
                            </p>
                        </div>
                    </div></template
                >
            </section>
        </main>
    </div>
</template>

<style>
.brand-account-menu {
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
.brand-account-menu [role='menuitem'] {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 6px;
    cursor: pointer;
    outline: none;
    text-decoration: none;
}
.brand-account-menu [data-highlighted] {
    background: #f6edf1;
    color: #8e1650;
}
</style>
