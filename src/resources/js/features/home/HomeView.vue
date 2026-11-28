<script setup lang="ts">
import '../../../css/home.css';
import { computed, onMounted, ref, type Component } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, Link2, RefreshCw, UserRound, Users, X } from '@lucide/vue';
import Tabs from '../../components/Tabs.vue';
import EmptyState from '../../components/EmptyState.vue';
import { money } from '../pipeline/money';
import { changeText, activityLabels, conversationKinds } from '../pipeline/activity';
import { relativeTime } from '../portals/activity';
import SoonPill from '../../components/SoonPill.vue';
import type { BrandHome, CreatorHome, HomeProps, HomeStage, Money } from './types';

const props = defineProps<HomeProps & { workspaceName: string }>();

type Task = { key: string; label: string; detail?: string; href: string; urgent?: boolean };
type Metric = { label: string; value: string; scope: string; href?: string };
type Cell = { text: string; href?: string; stage?: string; muted?: boolean };
/** Reads as `before` + linked `name` + `after`. */
type ActivityRow = { id: string; before: string; name: string; after: string; href: string; at: string | null };

const brand = computed<BrandHome | null>(() => (props.experience === 'brand' ? props.home : null));
const creator = computed<CreatorHome | null>(() => (props.experience !== 'brand' ? props.home : null));
const professional = computed(() => props.experience === 'professional');
const base = computed(() => (professional.value ? '/professional' : '/partner'));
const leadsHref = computed(() => (professional.value ? '/professional/leads' : '/partner/leads'));

const stageColors: Record<string, string> = {
    lead: 'var(--wd-stage-lead)',
    pitched: 'var(--wd-stage-pitched)',
    negotiating: 'var(--wd-stage-negotiating)',
    onboarded: 'var(--wd-stage-onboarded)',
    active: 'var(--wd-brand)',
    'repeat-client': 'var(--wd-brand-hover)',
    lost: 'var(--wd-muted)',
};
const stageName = (value: string) => props.home.stages.find((stage) => stage.value === value)?.label ?? value;
const count = (value: number, one: string, many = `${one}s`) =>
    `${value.toLocaleString('en')} ${value === 1 ? one : many}`;
const moneyText = (totals: Money[]) => totals.map((total) => money(total.amount_minor, total.currency)).join(' · ');
const dateTime = (value: string | null) =>
    value
        ? new Date(value).toLocaleString('en-US', {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
const shortDate = (value: string | null) =>
    value ? new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';

// Your tasks: work that already exists in the workspace's tools, linked to where it is done.
const tasks = computed<Task[]>(() => {
    if (brand.value) {
        const { pendingInvitations, updatedWeek, registeredRecent } = brand.value.tasks;
        return [
            ...(pendingInvitations
                ? [
                      {
                          key: 'invitations',
                          label: `${count(pendingInvitations, 'partner invitation')} awaiting acceptance`,
                          href: '/brand',
                      },
                  ]
                : []),
            ...(updatedWeek
                ? [
                      {
                          key: 'updated',
                          label: `${count(updatedWeek, 'registered lead')} updated this week`,
                          href: '/brand/lead-registrations',
                      },
                  ]
                : []),
            ...(registeredRecent
                ? [
                      {
                          key: 'recent',
                          label: `${count(registeredRecent, 'lead')} registered in the last 30 days`,
                          href: '/brand/lead-registrations?window=30d',
                      },
                  ]
                : []),
        ];
    }
    const work = creator.value!.tasks;
    const now = Date.now();
    return [
        ...work.reminders.map((reminder) => ({
            key: `reminder-${reminder.id}`,
            label: reminder.lead ? `${reminder.title} · ${reminder.lead}` : reminder.title,
            detail: new Date(reminder.due_at).getTime() < now ? 'Overdue' : `Due ${dateTime(reminder.due_at)}`,
            urgent: new Date(reminder.due_at).getTime() < now,
            href: `${base.value}/contacts?state=due`,
        })),
        ...work.onboarding.map((request) => ({
            key: `onboarding-${request.id}`,
            label: `Review onboarding: ${request.lead ?? request.label}`,
            detail: request.submitted_at ? `Submitted ${shortDate(request.submitted_at)}` : undefined,
            href: `${base.value}/onboarding?request=${request.id}`,
        })),
        ...(work.leadsDue
            ? [
                  {
                      key: 'leads-due',
                      label: `${count(work.leadsDue, professional.value ? 'client lead' : 'lead')} with a next action due`,
                      href: `${leadsHref.value}?due=1`,
                  },
              ]
            : []),
    ];
});
const tasksMore = computed(() => {
    if (!creator.value) return null;
    const { reminderTotal, reminders } = creator.value.tasks;
    return reminderTotal > reminders.length
        ? { href: `${base.value}/contacts?state=due`, label: `View all due reminders (${reminderTotal})` }
        : null;
});

// The contextual prompt points to an existing action this workspace can take.
const prompt = computed<{ title: string; body: string; href: string; action: string; icon: Component } | null>(() => {
    if (brand.value) {
        return brand.value.canManage
            ? {
                  title: 'Invite partners with a reusable join link',
                  body: 'Create a partner sign-up link or send personal invitations from the Partner hub. Partners who join appear in your roster.',
                  href: '/brand',
                  action: 'Open Partner hub',
                  icon: Link2,
              }
            : null;
    }
    const published = creator.value!.profilePublished;
    if (professional.value)
        return {
            title: published ? 'Share your client inquiry link' : 'Publish your profile / portfolio',
            body: published
                ? 'Share a lead link from your profile. Client inquiries arrive in Client leads, ready for follow-up.'
                : 'Publish your profile and create a lead link. Client inquiries arrive in Client leads, ready for follow-up.',
            href: '/professional/profile',
            action: 'Open Profile / portfolio',
            icon: UserRound,
        };
    return {
        title: published ? 'Collect brand leads with your media kit link' : 'Publish your media kit',
        body: published
            ? 'Share a lead link from your media kit in your bio. Brands that enquire land in My leads, ready to pitch.'
            : 'Publish your media kit and create a lead link. Brands that enquire land in My leads, ready to pitch.',
        href: '/partner/media-kit',
        action: 'Open media kit',
        icon: Users,
    };
});
const PROMPT_KEY = computed(() => `weavdash.home.prompt.${props.experience}`);
const promptDismissed = ref(false);
onMounted(() => {
    // A display preference only; storage may be unavailable.
    try {
        promptDismissed.value = window.localStorage.getItem(PROMPT_KEY.value) === 'dismissed';
    } catch {
        promptDismissed.value = false;
    }
});
function dismissPrompt(): void {
    promptDismissed.value = true;
    try {
        window.localStorage.setItem(PROMPT_KEY.value, 'dismissed');
    } catch {
        // Hidden for this page view only.
    }
    document.getElementById('home-views-tab-analytics')?.focus();
}

const metrics = computed<Metric[]>(() => {
    if (brand.value) {
        const figures = brand.value.metrics;
        return [
            {
                label: 'Active partners',
                value: figures.activePartners.toLocaleString('en'),
                scope: 'Partnerships currently active',
                href: '/brand',
            },
            {
                label: 'Registered leads',
                value: figures.leadsRegistered.toLocaleString('en'),
                scope: 'Leads partners registered with you',
                href: '/brand/lead-registrations',
            },
            {
                label: 'Registered (30 days)',
                value: figures.registeredRecent.toLocaleString('en'),
                scope: 'Registrations in the last 30 days',
                href: '/brand/lead-registrations?window=30d',
            },
            {
                label: 'Partners with leads',
                value: figures.partnersWithLeads.toLocaleString('en'),
                scope: 'Partners with at least one registration',
            },
        ];
    }
    const figures = creator.value!.metrics;
    const client = professional.value ? 'client leads' : 'leads';
    return [
        {
            label: professional.value ? 'Open client leads' : 'Open leads',
            value: figures.openLeads.toLocaleString('en'),
            scope: 'Every stage except Lost',
            href: leadsHref.value,
        },
        {
            label: 'Open pipeline value',
            value: figures.openValue.length ? moneyText(figures.openValue) : '—',
            scope: figures.openValue.length ? `Value of open ${client}, per currency` : `No open ${client} yet`,
        },
        {
            label: 'Reminders due',
            value: figures.remindersDue.toLocaleString('en'),
            scope: 'Overdue or due in the next 7 days',
            href: `${base.value}/contacts?state=due`,
        },
        {
            label: 'Onboarding to review',
            value: figures.onboardingReview.toLocaleString('en'),
            scope: 'Submitted forms awaiting your review',
            href: `${base.value}/onboarding?status=submitted`,
        },
        ...(figures.partnerships !== null
            ? [
                  {
                      label: 'Brand partnerships',
                      value: figures.partnerships.toLocaleString('en'),
                      scope: 'Active partnerships with Brands',
                      href: '/partner/brand-sharing',
                  },
              ]
            : []),
    ];
});

const chart = computed(() => {
    const stages: HomeStage[] = props.home.stages;
    const total = stages.reduce((sum, stage) => sum + stage.count, 0);
    const unit = brand.value ? 'registered lead' : professional.value ? 'client lead' : 'lead';
    return {
        title: brand.value
            ? 'Registered leads by stage'
            : professional.value
              ? 'Client leads by stage'
              : 'Leads by stage',
        total,
        unit,
        stages,
        summary: stages.map((stage) => `${stage.label} ${stage.count}`).join(', '),
    };
});

const recent = computed<{ title: string; columns: string[]; rows: Cell[][]; href: string; empty: string }>(() => {
    if (brand.value)
        return {
            title: 'Recently registered leads',
            columns: ['Lead', 'Partner', 'Stage', 'Registered'],
            rows: brand.value.recent.map((row) => [
                { text: row.name, href: `/brand/lead-registrations/${row.id}` },
                { text: row.partner_name },
                { text: stageName(row.stage), stage: row.stage },
                { text: shortDate(row.registered_at), muted: true },
            ]),
            href: '/brand/lead-registrations',
            empty: 'Leads your partners register with you will appear here.',
        };
    return {
        title: professional.value ? 'Recently updated client leads' : 'Recently updated leads',
        columns: [professional.value ? 'Client' : 'Brand', 'Stage', 'Value', 'Next action'],
        rows: creator.value!.recent.map((row) => [
            { text: row.name, href: `${leadsHref.value}?entry=${row.id}` },
            { text: stageName(row.stage), stage: row.stage },
            { text: money(row.value_minor, row.currency) },
            { text: row.next_action_due ? dateTime(row.next_action_due) : 'None set', muted: true },
        ]),
        href: leadsHref.value,
        empty: professional.value
            ? 'Client leads you add or capture will appear here.'
            : 'Leads you add or capture will appear here.',
    };
});

const activity = computed<ActivityRow[]>(() => {
    if (brand.value)
        return brand.value.activity.map((row) => ({
            id: row.id,
            before: `${row.partner_name} ${row.event === 'updated' ? 'updated shared details for' : 'registered'}`,
            name: row.display_name,
            after: '',
            href: `/brand/lead-registrations/${row.id}`,
            at: row.event === 'updated' ? row.shared_updated_at : row.registered_at,
        }));
    return creator.value!.activity.map((row) => {
        let after: string;
        if ((conversationKinds as readonly string[]).includes(row.kind)) {
            after = `${activityLabels[row.kind] ?? row.kind} logged`;
        } else {
            const change = changeText(
                { id: row.id, kind: row.kind, body: row.body, occurred_at: row.occurred_at ?? '' },
                stageName,
            );
            after = change.value ? `${change.lead} ${change.value}` : change.lead;
        }
        return {
            id: row.id,
            before: '',
            name: row.name,
            after: `: ${after}`,
            href: `${leadsHref.value}?entry=${row.entry_id}`,
            at: row.occurred_at,
        };
    });
});

const view = ref('analytics');
const tabs = [
    { id: 'analytics', label: 'Analytics' },
    { id: 'activity', label: 'Activity' },
];
const refreshing = ref(false);
function refresh(): void {
    router.reload({
        only: ['home'],
        onStart: () => {
            refreshing.value = true;
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <div class="wd-home">
        <header class="wd-home-heading">
            <h1>Home</h1>
            <p>{{ workspaceName }}</p>
        </header>

        <section class="wd-home-card wd-home-tasks" aria-labelledby="home-tasks-title">
            <h2 id="home-tasks-title">Your tasks</h2>
            <ul v-if="tasks.length" class="wd-home-task-list">
                <li v-for="task in tasks" :key="task.key">
                    <Link :href="task.href" class="wd-home-task" :class="{ 'is-urgent': task.urgent }"
                        >{{ task.label
                        }}<span v-if="task.detail" class="wd-home-task-detail">{{
                            `\u00a0· ${task.detail}`
                        }}</span></Link
                    >
                </li>
            </ul>
            <p v-else class="wd-home-muted">
                {{
                    brand
                        ? 'Nothing needs your attention right now.'
                        : 'No reminders are due and nothing is waiting for review.'
                }}
            </p>
            <Link v-if="tasksMore" :href="tasksMore.href" class="wd-home-link">{{ tasksMore.label }}</Link>
        </section>

        <section
            v-if="prompt && !promptDismissed"
            class="wd-home-card wd-home-prompt"
            aria-labelledby="home-prompt-title"
        >
            <span class="wd-home-prompt-icon" aria-hidden="true"><component :is="prompt.icon" :size="24" /></span>
            <div class="wd-home-prompt-body">
                <h2 id="home-prompt-title">{{ prompt.title }}</h2>
                <p>{{ prompt.body }}</p>
                <Link :href="prompt.href" class="wd-home-link"
                    >{{ prompt.action }}<ArrowRight :size="18" aria-hidden="true"
                /></Link>
            </div>
            <button type="button" class="wd-home-icon-button wd-home-dismiss" @click="dismissPrompt">
                <X :size="18" aria-hidden="true" /><span class="wd-sr-only">Dismiss: {{ prompt.title }}</span>
            </button>
        </section>

        <div class="wd-home-views">
            <Tabs v-model="view" label="Home views" :tabs="tabs" id-prefix="home-views" />
            <span class="wd-home-unavailable-tab" aria-disabled="true">Feed<SoonPill /></span>
        </div>

        <div
            v-show="view === 'analytics'"
            id="home-views-panel-analytics"
            role="tabpanel"
            aria-labelledby="home-views-tab-analytics"
            class="wd-home-panel"
        >
            <section class="wd-home-card wd-home-dashboard-bar" aria-labelledby="home-overview-title">
                <h2 id="home-overview-title">Workspace overview</h2>
                <p class="wd-home-muted wd-home-updated">
                    <span aria-live="polite">{{
                        refreshing ? 'Refreshing…' : `Updated ${relativeTime(home.clock).toLowerCase()}`
                    }}</span>
                </p>
                <button type="button" class="wd-home-icon-button" :disabled="refreshing" @click="refresh">
                    <RefreshCw :size="18" aria-hidden="true" /><span class="wd-sr-only">Refresh overview</span>
                </button>
            </section>

            <div class="wd-home-metrics">
                <article v-for="metric in metrics" :key="metric.label" class="wd-stat-card">
                    <h3>{{ metric.label }}</h3>
                    <p class="wd-stat-value" :class="{ 'wd-home-money': metric.value.includes(' ') }">
                        {{ metric.value }}
                    </p>
                    <p class="wd-hint">{{ metric.scope }}</p>
                    <Link v-if="metric.href" :href="metric.href"
                        >View<span class="wd-sr-only"> {{ metric.label.toLowerCase() }}</span></Link
                    >
                </article>
            </div>

            <div class="wd-home-grid">
                <section class="wd-home-card wd-home-stages" aria-labelledby="home-stages-title">
                    <h3 id="home-stages-title">{{ chart.title }}</h3>
                    <div
                        v-if="chart.total"
                        class="wd-home-stage-bar"
                        role="img"
                        :aria-label="`${chart.title}: ${chart.summary}`"
                    >
                        <span
                            v-for="stage in chart.stages.filter((item) => item.count)"
                            :key="stage.value"
                            :style="{ flexGrow: stage.count, background: stageColors[stage.value] }"
                        />
                    </div>
                    <div v-else class="wd-home-stage-bar is-empty" aria-hidden="true" />
                    <ul class="wd-home-stage-list">
                        <li v-for="stage in chart.stages" :key="stage.value">
                            <span
                                class="wd-home-dot"
                                :style="{ background: stageColors[stage.value] }"
                                aria-hidden="true"
                            />
                            <span class="wd-home-stage-name">{{ stage.label }}</span>
                            <span class="wd-home-stage-count">{{ count(stage.count, chart.unit) }}</span>
                            <span
                                v-if="!brand"
                                class="wd-home-stage-value"
                                :class="{ 'is-empty': !stage.totals.length }"
                                >{{ stage.totals.length ? moneyText(stage.totals) : '—' }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section class="wd-home-card wd-home-recent" aria-labelledby="home-recent-title">
                    <div class="wd-home-card-header">
                        <h3 id="home-recent-title">{{ recent.title }}</h3>
                        <Link :href="recent.href" class="wd-home-link"
                            >View all<span class="wd-sr-only"> {{ recent.title.toLowerCase() }}</span></Link
                        >
                    </div>
                    <div v-if="recent.rows.length" class="overflow-x-auto wd-home-table-scroll">
                        <table class="wd-data-table wd-home-table">
                            <caption>
                                {{
                                    recent.title
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th v-for="column in recent.columns" :key="column" scope="col">{{ column }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in recent.rows" :key="index">
                                    <td v-for="(cell, column) in row" :key="column" :class="{ 'is-muted': cell.muted }">
                                        <Link v-if="cell.href" :href="cell.href" class="wd-home-record">{{
                                            cell.text
                                        }}</Link>
                                        <span v-else-if="cell.stage" class="wd-home-pill"
                                            ><span
                                                class="wd-home-dot"
                                                :style="{ background: stageColors[cell.stage] }"
                                                aria-hidden="true"
                                            />{{ cell.text }}</span
                                        >
                                        <template v-else>{{ cell.text }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else title="Nothing here yet" :description="recent.empty" />
                </section>
            </div>
        </div>

        <div
            v-show="view === 'activity'"
            id="home-views-panel-activity"
            role="tabpanel"
            aria-labelledby="home-views-tab-activity"
            class="wd-home-panel"
        >
            <section class="wd-home-card" aria-labelledby="home-activity-title">
                <h2 id="home-activity-title">Recent activity</h2>
                <p class="wd-home-muted">
                    {{
                        brand
                            ? 'Registrations and shared-detail updates from your partners.'
                            : 'Changes and logged conversations across your leads in this workspace.'
                    }}
                </p>
                <ol v-if="activity.length" class="wd-home-activity">
                    <li v-for="item in activity" :key="item.id">
                        <span
                            >{{ item.before ? `${item.before} ` : ''
                            }}<Link :href="item.href" class="wd-home-record">{{ item.name }}</Link
                            >{{ item.after }}</span
                        >
                        <time v-if="item.at" :datetime="item.at" :title="dateTime(item.at)">{{
                            relativeTime(item.at)
                        }}</time>
                    </li>
                </ol>
                <EmptyState
                    v-else
                    title="No activity yet"
                    :description="
                        brand
                            ? 'Activity appears when partners register or update leads with you.'
                            : 'Activity appears as you add leads, change stages and log conversations.'
                    "
                />
            </section>
        </div>
    </div>
</template>
