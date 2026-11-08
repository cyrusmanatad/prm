<script setup lang="ts">
import { recordResourceLabel } from '../features/entitlements/resources';
import { Head, Link, router } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
defineProps<{
    account: { name: string; email: string };
    workspace: { name: string };
    subscription: {
        state: string;
        plan: null | { name: string; starts_at: string; ends_at: string };
        usage: { resource: string; used: number; limit: number }[];
    };
}>();
const toolGroups = [
    {
        title: 'Client work',
        tools: [
            ['leads', 'Client leads', 'Manage your private client pipeline.'],
            ['contacts', 'Contacts and reminders', 'Keep client details and next steps together.'],
            ['onboarding', 'Client onboarding', 'Share intake forms and review submissions.'],
        ],
    },
    {
        title: 'Outreach and presence',
        tools: [
            ['outreach-templates', 'Outreach templates', 'Prepare reusable messages for manual outreach.'],
            ['automation', 'Automation and approvals', 'Manage schedules and review private follow-up drafts.'],
            ['profile', 'Profile / portfolio', 'Choose what to publish about your work.'],
        ],
    },
    {
        title: 'Finance',
        tools: [
            ['finance/invoices', 'Private invoice drafts', 'Owner-only drafts with exact line amounts.'],
            ['finance/payments', 'Manual payment records', 'Owner-only receipts and allocation history.'],
        ],
    },
];
function subscriptionDate(value: string): string {
    return (
        new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }).format(
            new Date(value),
        ) + ' UTC'
    );
}
</script>
<template>
    <Head title="Professional Hub" />
    <FoundationShell
        :account-name="account.name"
        :account-email="account.email"
        portal="professional"
        @logout="router.post('/logout')"
    >
        <div class="wd-workspace-content wd-finance wd-finance-sections pro-hub">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Professional Hub</h1>
                <p class="wd-description">
                    Your clients, work and professional presence. No brand connection required.
                </p>
            </header>
            <div class="pro-hub-grid">
                <div class="pro-tool-groups">
                    <ContentSection v-for="group in toolGroups" :key="group.title" :title="group.title">
                        <ul class="pro-tool-list">
                            <li v-for="[route, title, description] in group.tools" :key="route">
                                <Link :href="`/professional/${route}`"
                                    ><span
                                        ><strong>{{ title }}</strong
                                        ><span>{{ description }}</span></span
                                    ><span aria-hidden="true">→</span></Link
                                >
                            </li>
                        </ul>
                    </ContentSection>
                </div>
                <ContentSection title="Workspace subscription" class="pro-plan">
                    <p class="pro-status">
                        {{
                            subscription.state === 'active'
                                ? 'Active'
                                : subscription.state === 'pending activation'
                                  ? 'Pending activation'
                                  : subscription.state === 'scheduled'
                                    ? 'Scheduled'
                                    : 'Expired'
                        }}
                    </p>
                    <template v-if="subscription.plan">
                        <h3>{{ subscription.plan.name }}</h3>
                        <dl class="pro-plan-dates">
                            <dt>Starts</dt>
                            <dd>{{ subscriptionDate(subscription.plan.starts_at) }}</dd>
                            <dt>Ends</dt>
                            <dd>{{ subscriptionDate(subscription.plan.ends_at) }}</dd>
                        </dl>
                    </template>
                    <p v-if="subscription.state !== 'active'" class="wd-description">
                        {{
                            subscription.state === 'scheduled'
                                ? 'Access begins at the scheduled start time.'
                                : subscription.state === 'expired'
                                  ? 'The subscription has ended. Contact your administrator to renew access.'
                                  : 'An administrator must activate this workspace.'
                        }}
                        You can read saved work, export authorized records and manage account security.
                    </p>
                    <template v-if="subscription.usage.length"
                        ><h3>Record usage</h3>
                        <dl class="pro-usage">
                            <div v-for="row in subscription.usage" :key="row.resource">
                                <dt>{{ recordResourceLabel(row.resource) }}</dt>
                                <dd>{{ row.used }} / {{ row.limit }}</dd>
                            </div>
                        </dl></template
                    >
                </ContentSection>
            </div>
            <ContentSection
                title="Export saved records"
                description="Exports preserve your current record permissions. Finance is available only to the workspace owner."
            >
                <ul class="pro-exports">
                    <li v-for="resource in ['leads', 'contacts', 'reminders', 'invoices', 'payments']" :key="resource">
                        <a class="wd-link inline-flex min-h-11 items-center" :href="`/professional/exports/${resource}`"
                            >Export {{ resource }} as CSV</a
                        >
                    </li>
                </ul>
            </ContentSection>
            <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center"
                >Workspaces and account security</Link
            >
        </div>
    </FoundationShell>
</template>
