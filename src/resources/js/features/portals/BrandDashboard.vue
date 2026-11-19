<script setup lang="ts">
import PartnerAvatar from '../../components/PartnerAvatar.vue';
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { UserRoundMinus } from '@lucide/vue';
import BrandChrome from './BrandChrome.vue';
import Pagination from '../../components/Pagination.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateTime, query } from '../admin/format';
import { activityKinds, relativeTime } from './activity';
import type { PartnerRow, PortalData } from './types';
const props = defineProps<{ data: PortalData; fixture?: boolean }>();
// Synthetic reference content cannot be enabled in a production build.
const fixture = import.meta.env.DEV && props.fixture === true;
defineEmits<{ invite: []; remove: [id: string, trigger: HTMLElement] }>();
// Rows come from the server page. Older callers that pass only `relationships` get rows without figures.
const partners = computed<PartnerRow[]>(
    () =>
        props.data.partners ??
        props.data.relationships.map((row) => ({ ...row, leads_total: null, leads_30d: null, last_active: null })),
);
const pagination = computed(
    () =>
        props.data.partnerPagination ?? { page: 1, perPage: partners.value.length || 1, total: partners.value.length },
);
const applied = computed(() => props.data.partnerFilters?.q ?? '');
const search = ref(applied.value);
watch(applied, (value) => (search.value = value));
const activePartners = computed(() => props.data.metrics?.activePartners ?? props.data.relationships.length);
// Searching always returns to the first page; the address keeps the search for reload and Back/Forward.
function searchPartners(): void {
    router.get('/brand', search.value.trim() ? { q: search.value.trim() } : {}, { preserveScroll: true });
}
function clearSearch(): void {
    router.get('/brand', {}, { preserveScroll: true });
}
const pageHref = (page: number) => `/brand${query({ q: applied.value, page })}`;
const summary = computed(() => [
    {
        label: 'Active partners',
        value: String(activePartners.value),
        hint: 'Explicitly accepted partnerships',
    },
    {
        label: 'Sign-up link',
        value: props.data.canManage ? (props.data.joinLink ? 'Active' : 'Off') : 'Restricted',
        hint: props.data.canManage ? 'Manage in Invite partners' : 'Owner or admin access required',
    },
    { label: 'Partner-sourced revenue', value: 'Unavailable', hint: 'Not yet implemented' },
    {
        label: 'Leads registered',
        value: props.data.metrics ? props.data.metrics.leadsRegistered.toLocaleString('en') : 'Unavailable',
        hint: 'Leads partners currently share with you, all dates and stages',
        href: props.data.metrics ? '/brand/lead-registrations' : undefined,
        linkLabel: 'View lead registrations',
    },
    ...['Days to first sale', 'Payouts due Friday'].map((label) => ({
        label,
        value: 'Unavailable',
        hint: 'Not yet implemented',
    })),
]);
</script>
<template>
    <BrandChrome
        current="hub"
        :workspace-name="data.workspace.name"
        :account-name="data.account.name"
        :account-email="data.account.email"
        :can-invite="data.canManage"
        show-invite
        :fixture="fixture"
        @invite="$emit('invite')"
    >
        <main id="main-content" class="mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
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
                        <Link
                            v-if="'href' in card && card.href"
                            :href="card.href"
                            class="mt-1 inline-flex min-h-11 items-center text-[13px] font-bold underline hover:text-brand"
                            >{{ card.linkLabel }}</Link
                        >
                    </div></template
                >
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <section
                    class="min-w-0 flex-[999_1_682px] rounded-2xl border border-line bg-white p-5"
                    aria-labelledby="partners-title"
                >
                    <template v-if="fixture">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h2 id="partners-title" class="font-display text-[18px] font-bold">Partners</h2>
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
                        ><div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h2 id="partners-title" class="font-display text-[18px] font-bold">Partners</h2>
                            <form
                                class="flex min-w-0 flex-wrap items-center gap-2"
                                role="search"
                                @submit.prevent="searchPartners"
                            >
                                <label for="partner-search" class="sr-only">Search partners by name</label>
                                <input
                                    id="partner-search"
                                    v-model="search"
                                    type="search"
                                    maxlength="120"
                                    placeholder="Search partners…"
                                    class="wd-table-input w-[220px] min-w-0 flex-[1_1_160px]"
                                />
                                <button type="submit" class="wd-table-button">Search</button>
                            </form>
                        </div>
                        <div class="mb-3 flex flex-wrap items-center gap-1.5">
                            <span class="rounded-full bg-brand px-2.5 py-[5px] text-[12px] font-bold text-white"
                                >All {{ activePartners }}</span
                            ><template v-if="applied"
                                ><span class="rounded-full bg-cream px-2.5 py-[5px] text-[12px] font-semibold"
                                    >Matching “{{ applied }}”: {{ pagination.total }}</span
                                ><button type="button" class="wd-link text-[13px]" @click="clearSearch">
                                    Clear search
                                </button></template
                            ><span v-else class="rounded-full bg-cream px-2.5 py-[5px] text-[12px]"
                                >Segments unavailable</span
                            >
                        </div>
                        <div
                            v-if="partners.length"
                            class="overflow-x-auto"
                            tabindex="0"
                            role="region"
                            aria-label="Partners table"
                        >
                            <table class="w-full min-w-[820px] text-left text-[14px]">
                                <thead>
                                    <tr>
                                        <th scope="col" class="p-3">Partner</th>
                                        <th scope="col" class="p-3">Tier</th>
                                        <th scope="col" class="p-3">Leads (30d)</th>
                                        <th scope="col" class="p-3">Sales</th>
                                        <th scope="col" class="p-3">Revenue</th>
                                        <th scope="col" class="p-3">Health</th>
                                        <th scope="col" class="p-3">Last active</th>
                                        <th scope="col" class="p-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in partners" :key="row.id" class="border-t border-line">
                                        <th scope="row" class="px-3 py-2 align-middle font-normal">
                                            <div class="wd-partner-identity">
                                                <PartnerAvatar
                                                    :name="row.creator_name"
                                                    :seed="row.id"
                                                    :paused="row.paused"
                                                />
                                                <div class="min-w-0">
                                                    <Link
                                                        :href="`/brand/partners/${row.id}`"
                                                        class="font-bold underline underline-offset-4 hover:text-brand"
                                                        >{{ row.creator_name }}</Link
                                                    >
                                                    <p v-if="row.paused" class="mt-1">
                                                        <StatusBadge label="Paused" tone="warning" />
                                                        <span class="mt-1 block text-[12px] text-muted"
                                                            >This partner's workspace is currently unavailable. The
                                                            partnership is kept.</span
                                                        >
                                                    </p>
                                                    <p v-else class="text-[12px] text-muted">Active partnership</p>
                                                </div>
                                            </div>
                                        </th>
                                        <td class="px-3 py-2 align-middle text-muted">Unavailable</td>
                                        <td class="px-3 py-2 align-middle">
                                            <Link
                                                v-if="row.leads_30d !== null"
                                                :href="`/brand/lead-registrations${query({ partner: row.id, window: '30d' })}`"
                                                class="wd-cell-link font-bold underline underline-offset-4 hover:text-brand"
                                                v-bind="{
                                                    'aria-label': `${row.leads_30d} leads registered by ${row.creator_name} in the last 30 days`,
                                                }"
                                                >{{ row.leads_30d }}</Link
                                            >
                                            <span v-else class="text-muted">{{
                                                row.paused ? 'Paused' : 'Unavailable'
                                            }}</span>
                                        </td>
                                        <td v-for="column in 3" :key="column" class="px-3 py-2 align-middle text-muted">
                                            Unavailable
                                        </td>
                                        <td class="px-3 py-2 align-middle">
                                            <template v-if="row.last_active">
                                                <time
                                                    :datetime="row.last_active.at"
                                                    :title="dateTime(row.last_active.at)"
                                                    >{{ relativeTime(row.last_active.at) }}</time
                                                >
                                                <span class="block text-[12px] text-muted">{{
                                                    activityKinds[row.last_active.kind]
                                                }}</span>
                                            </template>
                                            <span v-else class="text-muted">{{
                                                row.paused ? 'Paused' : 'Not yet recorded'
                                            }}</span>
                                        </td>
                                        <td class="px-3 py-2 align-middle">
                                            <button
                                                v-if="data.canManage"
                                                type="button"
                                                class="wd-table-icon-button is-danger"
                                                :aria-label="`End partnership with ${row.creator_name}`"
                                                :title="`End partnership with ${row.creator_name}`"
                                                @click="$emit('remove', row.id, $event.currentTarget as HTMLElement)"
                                            >
                                                <UserRoundMinus :size="16" aria-hidden="true" focusable="false" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="!partners.length" class="wd-empty mt-3">
                            {{
                                applied
                                    ? 'No partners match this search. Clear the search to see all partners.'
                                    : 'No active partners. Invite a partner to begin.'
                            }}
                        </p>
                        <Pagination
                            v-else
                            class="mt-3"
                            label="Partners pages"
                            noun="partners"
                            :page="pagination.page"
                            :per-page="pagination.perPage"
                            :count="partners.length"
                            :total="pagination.total"
                            :href-for="pageHref"
                    /></template>
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
            <slot name="after-partners" />

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
                            You see a lead only after a partner chooses to register it with you. Partners' other
                            prospects and private notes stay private. Approval and conflict rules are unavailable.
                        </p>
                        <Link
                            href="/brand/lead-registrations"
                            class="mt-2 inline-flex min-h-11 items-center text-[14px] font-bold underline hover:text-brand"
                            >View lead registrations →</Link
                        ></template
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
                            class="mt-3 min-h-11 rounded-lg border border-line px-3 py-2 text-[13px]"
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
    </BrandChrome>
</template>
