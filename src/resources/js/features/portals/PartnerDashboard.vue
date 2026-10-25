<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LeadManager from '../pipeline/LeadManager.vue';
import PipelineBoard from '../pipeline/PipelineBoard.vue';
import PartnerChrome from './PartnerChrome.vue';
import { money } from '../pipeline/money';
import type { PortalData } from './types';
const props = defineProps<{ data: PortalData; fixture?: boolean }>();
// Synthetic reference content cannot be enabled in a production build.
const fixture = import.meta.env.DEV && props.fixture === true;
defineEmits<{ invite: []; remove: [id: string, trigger: HTMLElement] }>();
const leadManager = ref<InstanceType<typeof LeadManager> | null>(null);
const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
function open(path: string): void {
    if (!fixture) router.get(path);
}
const summary = computed(() => [
    {
        label: 'Open pipeline',
        value:
            props.data.pipeline?.totals.map((t) => money(t.amount_minor, t.currency)).join(' · ') ||
            'No non-lost value',
        hint: 'Matching non-lost estimates, separated by currency',
    },
    ...['Expected this month', 'Commissions earned', 'Reply rate'].map((label) => ({
        label,
        value: 'Unavailable',
        hint: 'Not yet measured',
    })),
]);
</script>
<template>
    <PartnerChrome
        current="leads"
        :account-name="data.account.name"
        :account-email="fixture ? 'bea@example.test' : data.account.email"
        :fixture="fixture"
        :search-value="data.pipeline?.filters.q ?? ''"
    >
        <main id="main-content" class="mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
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

                    <PipelineBoard
                        v-if="fixture && data.pipeline"
                        :entries="data.pipeline.entries"
                        :counts="data.pipeline.counts"
                        :totals="data.pipeline.totals"
                        :filters="data.pipeline.filters"
                        :next-cursor="data.pipeline.nextCursor"
                    />
                    <LeadManager
                        v-else-if="data.pipeline"
                        ref="leadManager"
                        v-bind="data.pipeline"
                        :workspace="data.workspace"
                        :errors="data.errors"
                        :status="data.status"
                    />
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

                    <section class="wd-on-dark rounded-2xl bg-ink p-5 text-white">
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
                                    class="wd-action-danger mt-2 text-[13px]"
                                    type="button"
                                    @click="$emit('remove', row.id, $event.currentTarget as HTMLElement)"
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
    </PartnerChrome>
</template>
