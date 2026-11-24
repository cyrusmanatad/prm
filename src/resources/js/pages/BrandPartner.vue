<script setup lang="ts">
import PartnerAvatar from '../components/PartnerAvatar.vue';
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ContentSection from '../components/ContentSection.vue';
import DetailList, { type DetailItem } from '../components/DetailList.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import StatCard from '../components/StatCard.vue';
import StatusBadge from '../components/StatusBadge.vue';
import BackLink from '../features/admin/BackLink.vue';
import { dateTime, query } from '../features/admin/format';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import { activityKinds, relativeTime } from '../features/portals/activity';
import type { BrandWorkspace, PageMeta, PartnerRecord, RegistrationRow, StageOption } from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: BrandWorkspace;
    partner: PartnerRecord;
    registrations: RegistrationRow[];
    pagination: PageMeta;
    stages: StageOption[];
    canManage: boolean;
}>();
const self = computed(() => `/brand/partners/${props.partner.id}`);
const listHref = (window?: string) => `/brand/lead-registrations${query({ partner: props.partner.id, window })}`;
const website = computed(() => {
    const value = props.partner.profile?.website_url ?? '';
    // Only web addresses become links, whatever is stored.
    return /^https?:\/\//i.test(value) ? value : '';
});
const partnership = computed<DetailItem[]>(() => [
    { key: 'status', label: 'Partnership status' },
    { key: 'accepted', label: 'Partner since', value: dateTime(props.partner.accepted_at) },
    { key: 'activity', label: 'Last active' },
]);
const profile = computed<DetailItem[]>(() => [
    { key: 'contact_name', label: 'Business contact', value: props.partner.profile?.contact_name },
    { key: 'contact_email', label: 'Business contact email', value: props.partner.profile?.contact_email },
    { key: 'website_url', label: 'Website or public profile', value: props.partner.profile?.website_url },
    { key: 'description', label: 'About this partner', value: props.partner.profile?.description },
]);
</script>

<template>
    <Head :title="partner.creator_name" />
    <BrandChrome :workspace-name="workspace.name" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <BackLink href="/brand" label="Back to Partner hub" />
                <div class="wd-partner-identity mt-2">
                    <PartnerAvatar
                        :name="partner.creator_name"
                        :seed="partner.id"
                        :size="48"
                        :paused="partner.paused"
                    />
                    <h1 class="min-w-0 font-display text-[32px] font-bold [overflow-wrap:anywhere]">
                        {{ partner.creator_name }}
                    </h1>
                </div>
                <p class="mt-1.5 text-[15px] text-ink-soft">
                    Partner record: what {{ partner.creator_name }} shares with {{ workspace.name }}.
                </p>
            </div>
            <p v-if="partner.paused" class="wd-warning" role="status">
                This partner's workspace is currently unavailable. The partnership is kept, and their shared profile and
                registered leads will be shown again when the workspace is available.
            </p>
            <section v-else aria-labelledby="partner-summary-title">
                <h2 id="partner-summary-title" class="wd-sr-only">Registration summary</h2>
                <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
                    <StatCard
                        label="Leads registered"
                        :value="partner.leads_total"
                        scope="Currently shared with you by this partner, all dates and stages"
                        :href="listHref()"
                        link-label="View in lead registrations"
                    />
                    <StatCard
                        label="Leads (30d)"
                        :value="partner.leads_30d"
                        scope="Registered with you in the last 30 days"
                        :href="listHref('30d')"
                        link-label="View last 30 days"
                    />
                </div>
            </section>
            <div class="grid items-start gap-6 lg:grid-cols-2">
                <ContentSection title="Partnership">
                    <DetailList :items="partnership">
                        <template #status>
                            <StatusBadge v-if="partner.paused" label="Paused" tone="warning" />
                            <StatusBadge v-else label="Active" tone="success" />
                        </template>
                        <template #activity>
                            <template v-if="partner.last_active">
                                <time :datetime="partner.last_active.at">{{
                                    relativeTime(partner.last_active.at)
                                }}</time>
                                · {{ activityKinds[partner.last_active.kind] }}
                                <span class="wd-hint block">{{ dateTime(partner.last_active.at) }}</span>
                            </template>
                            <template v-else>{{ partner.paused ? 'Paused' : 'Not yet recorded' }}</template>
                        </template>
                    </DetailList>
                    <p class="wd-hint">
                        Last active is the latest of this partner signing in and a change to a lead they registered with
                        you.
                    </p>
                </ContentSection>
                <ContentSection
                    title="Shared profile"
                    description="Optional business details this partner chose to show the Brands they work with."
                >
                    <p v-if="partner.paused" class="wd-hint">Not shown while the partnership is paused.</p>
                    <DetailList v-else :items="profile" missing="Not shared">
                        <template v-if="partner.profile?.contact_email" #contact_email>
                            <a :href="`mailto:${partner.profile.contact_email}`" class="wd-link">{{
                                partner.profile.contact_email
                            }}</a>
                        </template>
                        <template v-if="website" #website_url>
                            <a :href="website" class="wd-link" target="_blank" rel="noopener noreferrer nofollow">{{
                                website
                            }}</a>
                        </template>
                        <template v-if="partner.profile?.description" #description>
                            <span class="whitespace-pre-line">{{ partner.profile.description }}</span>
                        </template>
                    </DetailList>
                </ContentSection>
            </div>
            <ContentSection
                title="Leads registered with you"
                :description="`Only leads ${partner.creator_name} registered with ${workspace.name}.`"
            >
                <RegistrationTable
                    v-if="registrations.length"
                    :caption="`Leads registered by ${partner.creator_name}`"
                    :rows="registrations"
                    :stages="stages"
                    :show-partner="false"
                    :detail-href="
                        (row) =>
                            `/brand/lead-registrations${query({ partner: row.partnership_id, registration: row.id })}`
                    "
                />
                <EmptyState
                    v-else
                    :title="partner.paused ? 'Registered leads are not shown while paused' : 'No leads registered yet'"
                    :description="
                        partner.paused ? undefined : 'A lead appears here after this partner registers it with you.'
                    "
                />
                <Pagination
                    class="mt-4"
                    label="Registered leads pages"
                    noun="registrations"
                    :page="pagination.page"
                    :per-page="pagination.perPage"
                    :count="registrations.length"
                    :total="pagination.total"
                    :href-for="(page) => `${self}${query({ page })}`"
                />
            </ContentSection>
        </main>
    </BrandChrome>
</template>
