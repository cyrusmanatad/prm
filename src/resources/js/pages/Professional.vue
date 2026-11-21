<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    BellRing,
    CalendarClock,
    ClipboardCheck,
    Download,
    FileText,
    Mail,
    Plus,
    Share2,
    UserRound,
    Users,
} from '@lucide/vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import StatusBadge, { type BadgeTone } from '../components/StatusBadge.vue';
import { recordResourceLabel } from '../features/entitlements/resources';
import { pipelineStages } from '../features/pipeline/stages';
import { professionalGroups } from '../features/portals/professionalNavigation';

type Summary = {
    leads: { open: number; stages: Record<string, number> };
    reminders: { overdue: number; dueDay: number; dueWeek: number };
    onboarding: { submitted: number; correction: number };
    invoices: null | { drafts: number; dueWeek: number };
    payments: null | number;
    templates: number;
    followUps: number;
    profilePublished: boolean;
};
type UpNext = {
    reminderTotal: number;
    onboardingTotal: number;
    reminders: { id: string; title: string; due_at: string; lead: string | null }[];
    onboarding: { id: string; label: string; lead: string; submitted_at: string | null }[];
};
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { name: string };
    subscription: {
        state: string;
        plan: null | { name: string; starts_at: string; ends_at: string };
        usage: { resource: string; used: number; limit: number }[];
    };
    isOwner: boolean;
    canWrite: boolean;
    summary: Summary;
    upNext: UpNext;
}>();

const UP_NEXT_ROWS = 6;
const stateBadge = computed<{ label: string; tone: BadgeTone }>(
    () =>
        ({
            active: { label: 'Active', tone: 'success' as const },
            'pending activation': { label: 'Pending activation', tone: 'warning' as const },
            scheduled: { label: 'Scheduled', tone: 'neutral' as const },
        })[props.subscription.state] ?? { label: 'Expired', tone: 'danger' as const },
);
const pausedMessage = computed(() =>
    props.subscription.state === 'active'
        ? ''
        : (props.subscription.state === 'scheduled'
              ? 'Access begins at the scheduled start time.'
              : props.subscription.state === 'expired'
                ? 'The subscription has ended. Contact your administrator to renew access.'
                : 'An administrator must activate this workspace.') +
          ' You can read saved work, export authorized records and manage account security.',
);
function utc(value: string): string {
    return (
        new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }).format(
            new Date(value),
        ) + ' UTC'
    );
}
const usage = computed(() =>
    props.subscription.usage.map((row) => {
        const ratio = row.limit > 0 ? row.used / row.limit : 1;
        return {
            ...row,
            label: recordResourceLabel(row.resource),
            percent: Math.min(100, Math.round(ratio * 100)),
            level: ratio >= 1 ? 'full' : ratio >= 0.8 ? 'near' : '',
        };
    }),
);

const openStages = computed(() =>
    pipelineStages
        .filter((stage) => (props.summary.leads.stages[stage.value] ?? 0) > 0)
        .map((stage) => ({ ...stage, count: props.summary.leads.stages[stage.value]! })),
);
const remindersDue = computed(() => props.summary.reminders.overdue + props.summary.reminders.dueDay);
const reminderDetail = computed(() => {
    const { overdue, dueDay, dueWeek } = props.summary.reminders;
    const parts = [overdue ? `${overdue} overdue` : '', dueDay ? `${dueDay} within 24 hours` : ''].filter(Boolean);
    parts.push(dueWeek ? `${dueWeek} this week` : 'Nothing due this week');
    return parts;
});

type Tool = { href: string; label: string; description: string; icon: Component };
const toolMeta: Record<string, { description: string; icon: Component }> = {
    '/professional/leads': { description: 'Your private client pipeline', icon: Users },
    '/professional/contacts': { description: 'Client details and next steps', icon: BellRing },
    '/professional/onboarding': { description: 'Intake forms and submissions', icon: ClipboardCheck },
    '/professional/brand-sharing': { description: 'Leads you share with brand partners', icon: Share2 },
    '/professional/outreach-templates': { description: 'Reusable manual messages', icon: Mail },
    '/professional/automation': { description: 'Schedules and follow-up drafts', icon: CalendarClock },
    '/professional/profile': { description: 'What you publish about your work', icon: UserRound },
    '/professional/finance/invoices': { description: 'Owner-only, exact line amounts', icon: FileText },
    '/professional/finance/payments': { description: 'Receipts and allocation history', icon: Banknote },
};
const links = (label: string): Tool[] =>
    (professionalGroups.find((group) => group.label === label)?.links ?? []).map((link) => ({
        ...link,
        ...toolMeta[link.href]!,
    }));
const toolColumns = [
    { title: 'Client work', tools: links('Client work') },
    { title: 'Outreach and presence', tools: [...links('Outreach'), ...links('Profile / portfolio')] },
    { title: 'Finance', tools: links('Finance'), ownerOnly: true },
];
function toolPill(href: string): null | { text: string; hot?: boolean } {
    const s = props.summary;
    const count = (value: number | null) => (value === null ? null : { text: String(value) });
    switch (href) {
        case '/professional/leads':
            return count(s.leads.open);
        case '/professional/contacts':
            return s.reminders.overdue
                ? { text: `${s.reminders.overdue} overdue`, hot: true }
                : s.reminders.dueDay
                  ? { text: `${s.reminders.dueDay} due soon` }
                  : null;
        case '/professional/onboarding':
            return s.onboarding.submitted ? { text: `${s.onboarding.submitted} to review` } : null;
        case '/professional/outreach-templates':
            return count(s.templates);
        case '/professional/automation':
            return s.followUps ? { text: `${s.followUps} to review` } : null;
        case '/professional/finance/invoices':
            return count(s.invoices?.drafts ?? null);
        case '/professional/finance/payments':
            return count(s.payments);
        default:
            return null;
    }
}

type QueueItem = {
    key: string;
    kind: 'reminder' | 'onboarding';
    title: string;
    meta: string | null;
    when: string;
    tone: 'overdue' | 'soon' | 'review';
    href: string;
    rank: number;
};
const dayFormat = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
function isDay(date: Date, offset: number): boolean {
    const day = new Date();
    day.setDate(day.getDate() + offset);
    return date.toDateString() === day.toDateString();
}
function dueLabel(value: string, overdue: boolean): string {
    const date = new Date(value);
    const day = isDay(date, 0)
        ? 'Today'
        : isDay(date, 1)
          ? 'Tomorrow'
          : isDay(date, -1)
            ? 'Yesterday'
            : dayFormat.format(date);
    return `${overdue ? 'Overdue · ' : ''}${day} ${timeFormat.format(date)}`;
}
const queue = computed<QueueItem[]>(() => {
    const now = Date.now();
    const reminders = props.upNext.reminders.map((row): QueueItem => {
        const due = new Date(row.due_at).getTime();
        const overdue = due < now;
        return {
            key: `reminder-${row.id}`,
            kind: 'reminder',
            title: row.title,
            meta: row.lead,
            when: dueLabel(row.due_at, overdue),
            tone: overdue ? 'overdue' : 'soon',
            href: `/professional/contacts?state=${overdue ? 'due' : 'pending'}&q=${encodeURIComponent(row.title)}`,
            rank: overdue ? due : due + 1e15,
        };
    });
    const onboarding = props.upNext.onboarding.map((row): QueueItem => ({
        key: `onboarding-${row.id}`,
        kind: 'onboarding',
        title: row.label,
        meta: row.lead,
        when: row.submitted_at ? `Submitted ${dayFormat.format(new Date(row.submitted_at))}` : 'Submitted',
        tone: 'review',
        href: `/professional/onboarding?request=${row.id}`,
        rank: 5e14 + (row.submitted_at ? new Date(row.submitted_at).getTime() : 0),
    }));
    return [...reminders, ...onboarding].sort((a, b) => a.rank - b.rank);
});
const queueFilter = ref<'' | 'reminder' | 'onboarding'>('');
const queueTotal = computed(() =>
    queueFilter.value === 'reminder'
        ? props.upNext.reminderTotal
        : queueFilter.value === 'onboarding'
          ? props.upNext.onboardingTotal
          : props.upNext.reminderTotal + props.upNext.onboardingTotal,
);
const queueChips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.upNext.reminderTotal + props.upNext.onboardingTotal },
    { value: 'reminder', label: 'Reminders', count: props.upNext.reminderTotal },
    { value: 'onboarding', label: 'Onboarding', count: props.upNext.onboardingTotal },
]);
const queueRows = computed(() =>
    queue.value.filter((item) => !queueFilter.value || item.kind === queueFilter.value).slice(0, UP_NEXT_ROWS),
);
const queueLink = computed(() =>
    queueFilter.value === 'onboarding'
        ? { href: '/professional/onboarding?status=submitted', label: 'All onboarding to review' }
        : { href: '/professional/contacts?state=pending', label: 'All open reminders' },
);
const exports = computed(() =>
    ['leads', 'contacts', 'reminders', ...(props.isOwner ? ['invoices', 'payments'] : [])].map((resource) => ({
        resource,
        label: resource[0]!.toUpperCase() + resource.slice(1),
    })),
);
</script>
<template>
    <Head title="Professional Hub" />
    <FoundationShell
        :account-name="account.name"
        :account-email="account.email"
        portal="professional"
        @logout="router.post('/logout')"
    >
        <div class="hub">
            <header class="hub-header">
                <div>
                    <h1>Professional Hub</h1>
                    <p>
                        <strong>{{ workspace.name }}</strong> · Your clients, work and professional presence.
                    </p>
                </div>
                <Link v-if="canWrite" href="/professional/leads?create=1" class="wd-table-button hub-primary"
                    ><Plus :size="16" aria-hidden="true" />Add client lead</Link
                >
            </header>

            <ul class="hub-stats" :class="{ 'is-three': !summary.invoices }" aria-label="Workspace summary">
                <li>
                    <Link href="/professional/leads">
                        <span class="hub-stat-label"
                            >Open client leads<ArrowRight :size="14" aria-hidden="true"
                        /></span>
                        <strong>{{ summary.leads.open }}</strong>
                        <span v-if="openStages.length" class="hub-stage-bar" aria-hidden="true"
                            ><i
                                v-for="stage in openStages"
                                :key="stage.value"
                                :style="{ flexGrow: stage.count, background: stage.dot }"
                        /></span>
                        <span class="hub-stat-detail">{{
                            openStages.length
                                ? openStages.map((stage) => `${stage.count} ${stage.label.toLowerCase()}`).join(' · ')
                                : 'No open leads yet'
                        }}</span>
                    </Link>
                </li>
                <li :class="{ 'is-alert': summary.reminders.overdue }">
                    <Link href="/professional/contacts?state=pending">
                        <span class="hub-stat-label">Reminders due<ArrowRight :size="14" aria-hidden="true" /></span>
                        <strong>{{ remindersDue }}</strong>
                        <span class="hub-stat-detail"
                            ><template v-for="(part, index) in reminderDetail" :key="part"
                                ><template v-if="index"> · </template><b v-if="part.endsWith('overdue')">{{ part }}</b
                                ><template v-else>{{ part }}</template></template
                            ></span
                        >
                    </Link>
                </li>
                <li>
                    <Link href="/professional/onboarding?status=submitted">
                        <span class="hub-stat-label"
                            >Onboarding to review<ArrowRight :size="14" aria-hidden="true"
                        /></span>
                        <strong>{{ summary.onboarding.submitted }}</strong>
                        <span class="hub-stat-detail">{{
                            summary.onboarding.correction
                                ? `${summary.onboarding.correction} waiting on client corrections`
                                : 'No corrections pending'
                        }}</span>
                    </Link>
                </li>
                <li v-if="summary.invoices">
                    <Link href="/professional/finance/invoices">
                        <span class="hub-stat-label">Invoice drafts<ArrowRight :size="14" aria-hidden="true" /></span>
                        <strong>{{ summary.invoices.drafts }}</strong>
                        <span class="hub-stat-detail"
                            >Owner only{{
                                summary.invoices.dueWeek ? ` · ${summary.invoices.dueWeek} due this week` : ''
                            }}</span
                        >
                    </Link>
                </li>
            </ul>

            <div class="hub-grid">
                <div class="hub-column">
                    <section class="hub-card" aria-labelledby="hub-next-heading">
                        <header class="hub-card-head">
                            <h2 id="hub-next-heading">Up next</h2>
                            <span class="hub-count">{{ upNext.reminderTotal + upNext.onboardingTotal }}</span>
                            <p class="hub-note">Overdue first, then onboarding, then the next 24 hours</p>
                        </header>
                        <template v-if="queue.length">
                            <div class="hub-chips">
                                <FilterChips
                                    label="Show in Up next"
                                    :chips="queueChips"
                                    :current="queueFilter"
                                    compact
                                    @select="queueFilter = $event as typeof queueFilter"
                                />
                            </div>
                            <ul class="hub-queue">
                                <li v-for="item in queueRows" :key="item.key">
                                    <Link :href="item.href">
                                        <span class="hub-when" :class="`is-${item.tone}`">{{ item.when }}</span>
                                        <span class="hub-queue-text"
                                            ><strong>{{ item.title }}</strong
                                            ><span v-if="item.meta">{{ item.meta }}</span></span
                                        >
                                        <span class="hub-kind">{{
                                            item.kind === 'reminder' ? 'Reminder' : 'Onboarding'
                                        }}</span>
                                    </Link>
                                </li>
                            </ul>
                            <footer class="hub-card-foot">
                                <span>Showing {{ queueRows.length }} of {{ queueTotal }}</span>
                                <Link :href="queueLink.href">{{ queueLink.label }}</Link>
                            </footer>
                        </template>
                        <EmptyState
                            v-else
                            title="Nothing due"
                            description="No overdue reminders, none due in the next 24 hours and no onboarding submissions to review."
                        />
                    </section>

                    <section class="hub-card" aria-labelledby="hub-tools-heading">
                        <header class="hub-card-head">
                            <h2 id="hub-tools-heading">Tools</h2>
                            <p class="hub-note">Also in the header menus</p>
                        </header>
                        <div class="hub-tools">
                            <div v-for="column in toolColumns" :key="column.title" class="hub-tool-group">
                                <h3>{{ column.title }}</h3>
                                <p v-if="column.ownerOnly && !isOwner" class="hub-tool-note">
                                    Finance is available only to the workspace owner.
                                </p>
                                <ul v-else>
                                    <li v-for="tool in column.tools" :key="tool.href">
                                        <Link :href="tool.href">
                                            <span class="hub-tool-icon" aria-hidden="true"
                                                ><component :is="tool.icon" :size="16"
                                            /></span>
                                            <span class="hub-tool-text"
                                                ><strong>{{ tool.label }}</strong
                                                ><span>{{ tool.description }}</span></span
                                            >
                                            <StatusBadge
                                                v-if="tool.href === '/professional/profile'"
                                                :label="summary.profilePublished ? 'Published' : 'Not published'"
                                                :tone="summary.profilePublished ? 'success' : 'neutral'"
                                            />
                                            <span
                                                v-else-if="toolPill(tool.href)"
                                                class="hub-count"
                                                :class="{ 'is-hot': toolPill(tool.href)!.hot }"
                                                >{{ toolPill(tool.href)!.text }}</span
                                            >
                                        </Link>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="hub-column">
                    <section class="hub-card" aria-labelledby="hub-plan-heading">
                        <header class="hub-card-head">
                            <h2 id="hub-plan-heading">Subscription</h2>
                            <StatusBadge :label="stateBadge.label" :tone="stateBadge.tone" />
                        </header>
                        <div class="hub-plan">
                            <template v-if="subscription.plan">
                                <p class="hub-plan-name">{{ subscription.plan.name }}</p>
                                <dl class="hub-plan-dates">
                                    <div>
                                        <dt>Starts</dt>
                                        <dd>{{ utc(subscription.plan.starts_at) }}</dd>
                                    </div>
                                    <div>
                                        <dt>Ends</dt>
                                        <dd>{{ utc(subscription.plan.ends_at) }}</dd>
                                    </div>
                                </dl>
                            </template>
                            <p
                                v-if="pausedMessage"
                                class="hub-plan-message"
                                :class="{ 'is-danger': subscription.state === 'expired' }"
                            >
                                {{ pausedMessage }}
                            </p>
                            <template v-if="usage.length">
                                <h3>Record usage</h3>
                                <ul class="hub-usage">
                                    <li
                                        v-for="row in usage"
                                        :key="row.resource"
                                        :class="row.level && `is-${row.level}`"
                                    >
                                        <span>{{ row.label }}</span>
                                        <span class="hub-usage-count"
                                            >{{ row.used }} / {{ row.limit
                                            }}<template v-if="row.level === 'full'"> · limit reached</template></span
                                        >
                                        <span class="hub-usage-bar" aria-hidden="true"
                                            ><i :style="{ width: `${row.percent}%` }"
                                        /></span>
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </section>

                    <section class="hub-card" aria-labelledby="hub-export-heading">
                        <header class="hub-card-head">
                            <h2 id="hub-export-heading">Export records</h2>
                            <p class="hub-note is-inline">CSV</p>
                        </header>
                        <div class="hub-exports">
                            <p>
                                Exports follow your current record permissions.<template v-if="isOwner">
                                    Finance exports are owner-only.</template
                                >
                            </p>
                            <ul>
                                <li v-for="item in exports" :key="item.resource">
                                    <a
                                        class="wd-table-button"
                                        :href="`/professional/exports/${item.resource}`"
                                        :download="`professional-${item.resource}.csv`"
                                        :aria-label="`Export ${item.resource} as CSV`"
                                        ><Download :size="14" aria-hidden="true" />{{ item.label }}</a
                                    >
                                </li>
                            </ul>
                        </div>
                        <footer class="hub-card-foot">
                            <Link href="/workspaces"
                                >Workspaces and account security<ArrowRight :size="14" aria-hidden="true"
                            /></Link>
                        </footer>
                    </section>
                </div>
            </div>
        </div>
    </FoundationShell>
</template>
<style scoped>
.hub {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
    color: var(--wd-ink);
    font-size: 14px;
    line-height: 1.4;
}
.hub :focus-visible {
    outline: 2px solid var(--wd-brand);
    outline-offset: 2px;
}
.hub-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.hub-header h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.hub-header p {
    margin: 4px 0 0;
    color: var(--wd-muted);
}
.hub-header p strong {
    color: var(--wd-body);
    overflow-wrap: anywhere;
}
.hub-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.hub .hub-primary:hover {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.hub-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.hub-stats.is-three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.hub-stats a {
    display: flex;
    flex-direction: column;
    gap: 2px;
    height: 100%;
    padding: 12px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
    color: inherit;
    text-decoration: none;
}
.hub-stats a:hover {
    border-color: var(--wd-control-line);
    background: var(--wd-paper);
}
.hub-stats .is-alert a {
    border-color: #e7b9c2;
}
.hub-stat-label {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.hub-stat-label svg {
    color: var(--wd-brand);
}
.hub-stats strong {
    font-family: var(--wd-font-display);
    font-size: 24px;
    font-variant-numeric: tabular-nums;
    line-height: 1.1;
}
.hub-stat-detail {
    color: var(--wd-muted);
    font-size: 12px;
}
.hub-stat-detail b {
    color: var(--wd-danger);
}
.hub-stage-bar {
    display: flex;
    gap: 2px;
    height: 6px;
    margin: 4px 0 2px;
    border-radius: 999px;
    overflow: hidden;
}
.hub-stage-bar i {
    flex-basis: 0;
}
.hub-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(300px, 360px);
    gap: 16px;
    align-items: start;
}
.hub-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}
.hub-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.hub-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 12px 14px 8px;
}
.hub-card-head h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.hub-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
.hub-count.is-hot {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.hub-note {
    margin: 0 0 0 auto;
    color: var(--wd-muted);
    font-size: 12px;
}
.hub-card-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 14px;
    border-top: 1px solid var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12.5px;
}
.hub-card-foot a {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-height: var(--wd-table-control);
    color: var(--wd-brand);
    font-weight: 700;
    text-decoration: none;
}
.hub-card-foot a:hover {
    text-decoration: underline;
}
.hub-chips {
    padding: 0 14px 10px;
}
.hub-queue {
    margin: 0;
    padding: 0;
    list-style: none;
    border-top: 1px solid var(--wd-divider);
}
.hub-queue li + li {
    border-top: 1px solid var(--wd-divider);
}
.hub-queue a {
    display: grid;
    grid-template-columns: 150px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    padding: 8px 14px;
    color: inherit;
    text-decoration: none;
}
.hub-queue a:hover {
    background: var(--wd-paper);
}
.hub-when {
    font-size: 12px;
    font-weight: 700;
}
.hub-when.is-overdue {
    color: var(--wd-danger);
}
.hub-when.is-soon {
    color: var(--wd-warning);
}
.hub-when.is-review {
    color: var(--wd-success);
}
.hub-queue-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.hub-queue-text strong,
.hub-tool-text strong {
    font-size: 13.5px;
    overflow-wrap: anywhere;
}
.hub-queue-text span,
.hub-tool-text span {
    color: var(--wd-muted);
    font-size: 12px;
}
.hub-kind {
    color: var(--wd-muted);
    font-size: 11.5px;
}
.hub-card :deep(.wd-empty-state) {
    margin: 0 14px 14px;
}
.hub-tools {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    border-top: 1px solid var(--wd-divider);
}
.hub-tool-group {
    min-width: 0;
    padding: 10px 8px 10px 14px;
}
.hub-tool-group + .hub-tool-group {
    border-left: 1px solid var(--wd-divider);
}
.hub-tool-group h3,
.hub-plan h3 {
    margin: 0 0 4px;
    color: var(--wd-muted);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.hub-tool-group ul {
    margin: 0;
    padding: 0;
    list-style: none;
}
.hub-tool-group a {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: -6px;
    padding: 6px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}
.hub-tool-group a:hover {
    background: var(--wd-paper);
}
.hub-tool-group a:hover strong {
    color: var(--wd-brand);
}
.hub-tool-icon {
    display: grid;
    flex: none;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: var(--wd-danger-bg);
    color: var(--wd-brand);
}
.hub-tool-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}
.hub-tool-text span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.hub-tool-note {
    margin: 6px 0;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.hub-plan {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 0 14px 14px;
}
.hub-plan-name {
    margin: 0;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.hub-plan-dates {
    display: grid;
    gap: 2px;
    margin: -6px 0 0;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.hub-plan-dates div {
    display: flex;
    gap: 6px;
}
.hub-plan-dates dt {
    min-width: 42px;
}
.hub-plan-dates dd {
    margin: 0;
}
.hub-plan-message {
    margin: 0;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
    font-size: 12.5px;
}
.hub-plan-message.is-danger {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.hub-usage {
    display: grid;
    gap: 7px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 12.5px;
}
.hub-usage li {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 3px 8px;
}
.hub-usage-count {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}
.hub-usage-bar {
    grid-column: 1 / -1;
    height: 5px;
    border-radius: 999px;
    background: var(--wd-divider);
    overflow: hidden;
}
.hub-usage-bar i {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--wd-stage-onboarded);
}
.hub-usage .is-near .hub-usage-count {
    color: var(--wd-warning);
}
.hub-usage .is-near .hub-usage-bar i {
    background: var(--wd-stage-pitched);
}
.hub-usage .is-full .hub-usage-count {
    color: var(--wd-danger);
}
.hub-usage .is-full .hub-usage-bar i {
    background: var(--wd-danger);
}
.hub-exports {
    padding: 0 14px 12px;
}
.hub-exports p {
    margin: 0 0 10px;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.hub-exports ul {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}
@media (min-width: 600px) and (pointer: fine) {
    .hub {
        --wd-table-control: 32px;
    }
}
@media (max-width: 1099px) {
    .hub-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (min-width: 700px) and (max-width: 1099px) {
    .hub-column + .hub-column {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: start;
    }
}
@media (max-width: 899px) {
    .hub-stats,
    .hub-stats.is-three {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .hub-tools {
        grid-template-columns: minmax(0, 1fr);
    }
    .hub-tool-group + .hub-tool-group {
        border-top: 1px solid var(--wd-divider);
        border-left: 0;
    }
}
@media (max-width: 599px) {
    .hub-header h1 {
        font-size: 24px;
    }
    .hub-header .hub-primary {
        flex: 1 1 100%;
    }
    .hub-stats {
        gap: 6px;
    }
    .hub-stats a {
        padding: 9px 12px;
    }
    .hub-stats strong {
        font-size: 20px;
    }
    .hub-note:not(.is-inline) {
        flex-basis: 100%;
        margin-left: 0;
    }
    .hub-queue a {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 2px 8px;
        min-height: 56px;
        padding: 10px 14px;
    }
    .hub-queue-text {
        grid-column: 1;
    }
    .hub-kind {
        grid-row: 1;
        grid-column: 2;
    }
    .hub-tool-group a {
        min-height: 44px;
    }
    .hub-exports li {
        flex: 1 1 calc(50% - 6px);
    }
    .hub-exports a {
        width: 100%;
    }
}
</style>
