<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, ExternalLink, Lock, Mail } from '@lucide/vue';
import Breadcrumbs from '../components/Breadcrumbs.vue';
import ContentSection from '../components/ContentSection.vue';
import DetailList, { type DetailItem } from '../components/DetailList.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import RecordHeader from '../components/RecordHeader.vue';
import PartnerAvatar from '../components/PartnerAvatar.vue';
import StatCard from '../components/StatCard.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useListFilters } from '../composables/useListFilters';
import { dateOnly, dateTime, query } from '../features/admin/format';
import RegistrationFilterBar from '../features/brand-leads/RegistrationFilterBar.vue';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import { activityKinds, relativeTime } from '../features/portals/activity';
import type {
    BrandWorkspace,
    PageMeta,
    PartnerRecord,
    PartnerRecordFilters,
    RegistrationRow,
    StageOption,
} from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: BrandWorkspace;
    partner: PartnerRecord;
    registrations: RegistrationRow[];
    pagination: PageMeta;
    filters: PartnerRecordFilters;
    facets: { stages: Record<string, number>; stageTotal: number };
    stages: StageOption[];
    canManage: boolean;
}>();
const self = computed(() => `/brand/partners/${props.partner.id}`);
// The record's list state lives in its own address, like the Lead registrations list.
const { pending, apply, href } = useListFilters(self.value, () => props.filters);
const listHref = (window?: string) => `/brand/lead-registrations${query({ partner: props.partner.id, window })}`;
const filtered = computed(() => !!(props.filters.q || props.filters.stage || props.filters.window));
/** Nothing to search or filter while paused or before the partner has registered a lead. */
const hasLeads = computed(() => !props.partner.paused && (props.partner.leads_total ?? 0) > 0);

const website = computed(() => {
    const value = props.partner.profile?.website_url ?? '';
    // Only web addresses become links, whatever is stored.
    return /^https?:\/\//i.test(value) ? value : '';
});
const contactEmail = computed(() => (props.partner.paused ? '' : (props.partner.profile?.contact_email ?? '')));
const profile = computed<DetailItem[]>(() => [
    { key: 'contact_name', label: 'Business contact', value: props.partner.profile?.contact_name },
    { key: 'contact_email', label: 'Email', value: props.partner.profile?.contact_email },
    { key: 'website_url', label: 'Website or public profile', value: props.partner.profile?.website_url },
    { key: 'description', label: 'About', value: props.partner.profile?.description },
]);
</script>

<template>
    <Head :title="partner.creator_name" />
    <BrandChrome :workspace-name="workspace.name" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page bp wd-portal-page flex flex-col gap-4 pt-5 pb-12">
            <Breadcrumbs :items="[{ label: 'Partner hub', href: '/brand' }, { label: partner.creator_name }]" />

            <RecordHeader eyebrow="Partner record" :title="partner.creator_name" heading-id="partner-name">
                <template #leading>
                    <PartnerAvatar
                        :name="partner.creator_name"
                        :seed="partner.id"
                        :size="48"
                        :paused="partner.paused"
                    />
                </template>
                <template #meta>
                    <div>
                        <dt class="wd-sr-only">Partnership status</dt>
                        <dd>
                            <StatusBadge v-if="partner.paused" label="Paused" tone="warning" />
                            <StatusBadge v-else label="Active" tone="success" />
                        </dd>
                    </div>
                    <div>
                        <dt>Partner since</dt>
                        <dd>
                            <time :datetime="partner.accepted_at" :title="dateTime(partner.accepted_at)">{{
                                dateOnly(partner.accepted_at)
                            }}</time>
                        </dd>
                    </div>
                    <div v-if="!partner.paused">
                        <dt>Last active</dt>
                        <dd>
                            <template v-if="partner.last_active">
                                <time :datetime="partner.last_active.at" :title="dateTime(partner.last_active.at)">{{
                                    relativeTime(partner.last_active.at)
                                }}</time>
                                · {{ activityKinds[partner.last_active.kind] }}
                            </template>
                            <template v-else>Not yet recorded</template>
                        </dd>
                    </div>
                </template>
                <template v-if="!partner.paused" #actions>
                    <a v-if="contactEmail" :href="`mailto:${contactEmail}`" class="wd-button wd-button-secondary"
                        ><Mail :size="16" aria-hidden="true" />Email
                        {{ partner.profile?.contact_name || 'business contact' }}</a
                    >
                    <Link :href="listHref()" class="wd-button wd-button-primary"
                        >Open in Lead registrations<ArrowRight :size="16" aria-hidden="true"
                    /></Link>
                </template>
                <p v-if="partner.paused" class="wd-warning bp-notice" role="status">
                    This partner's workspace is currently unavailable. The partnership is kept, and their shared profile
                    and registered leads will be shown again when the workspace is available.
                </p>
                <div v-else class="bp-summary">
                    <h2 class="wd-sr-only">Registration summary</h2>
                    <ul class="bp-figures">
                        <li>
                            <StatCard
                                compact
                                label="Leads registered"
                                :value="partner.leads_total"
                                scope="All dates and stages"
                                :href="listHref()"
                                link-label="View in Lead registrations"
                            />
                        </li>
                        <li>
                            <StatCard
                                compact
                                label="In the last 30 days"
                                :value="partner.leads_30d"
                                scope="Newly registered with you"
                                :href="listHref('30d')"
                                link-label="View last 30 days in Lead registrations"
                            />
                        </li>
                    </ul>
                    <p class="bp-scope">
                        Counts include only leads {{ partner.creator_name }} currently shares with
                        {{ workspace.name }} — not sales, revenue or their private pipeline.
                    </p>
                </div>
            </RecordHeader>

            <div class="wd-record-columns">
                <ContentSection
                    class="bp-leads"
                    title="Registered leads"
                    heading-id="registered-leads-heading"
                    :description="`Leads ${partner.creator_name} registered with ${workspace.name}. Open a lead to see its shared summary and contact.`"
                >
                    <template #header>
                        <header>
                            <h2 id="registered-leads-heading">
                                Registered leads<span v-if="hasLeads" class="bp-count">{{
                                    (partner.leads_total ?? 0).toLocaleString('en')
                                }}</span>
                            </h2>
                            <p class="wd-description">
                                Leads {{ partner.creator_name }} registered with {{ workspace.name }}. Open a lead to
                                see its shared summary and contact.
                            </p>
                        </header>
                    </template>
                    <RegistrationFilterBar
                        v-if="hasLeads"
                        id-prefix="partner-leads"
                        label="Filter registered leads"
                        :filters="filters"
                        :stages="stages"
                        :stage-counts="facets.stages"
                        :stage-total="facets.stageTotal"
                        :stage-href="(stage) => href(1, { stage })"
                        :pending="pending"
                        @apply="apply"
                    />
                    <RegistrationTable
                        v-if="registrations.length"
                        :class="{ 'is-pending': pending }"
                        v-bind="{ 'aria-busy': pending ? 'true' : undefined }"
                        :caption="`Leads registered by ${partner.creator_name}`"
                        :rows="registrations"
                        :stages="stages"
                        :show-partner="false"
                        :detail-href="
                            (row) =>
                                `/brand/lead-registrations${query({ partner: row.partnership_id, registration: row.id })}`
                        "
                        :sort="filters.sort"
                        :sort-href="(sort) => href(1, { sort })"
                    />
                    <EmptyState
                        v-else-if="partner.paused"
                        title="Registered leads are not shown while paused"
                        description="They return when the partner's workspace is available again."
                    />
                    <EmptyState
                        v-else-if="filtered"
                        title="No registrations match these filters"
                        :description="`Clear the filters to see every lead ${partner.creator_name} registered with you.`"
                    />
                    <EmptyState
                        v-else
                        title="No leads registered yet"
                        :description="`A lead appears here after ${partner.creator_name} registers it with ${workspace.name}. Partners choose which leads to share.`"
                    />
                    <Pagination
                        v-if="registrations.length"
                        label="Registered leads pages"
                        noun="registrations"
                        :page="pagination.page"
                        :per-page="pagination.perPage"
                        :count="registrations.length"
                        :total="pagination.total"
                        :href-for="(page) => href(page)"
                    />
                </ContentSection>

                <div class="wd-record-aside">
                    <ContentSection title="Shared profile" heading-id="shared-profile-heading">
                        <template #header>
                            <header>
                                <h2 id="shared-profile-heading">Shared profile</h2>
                                <p class="bp-owner">
                                    <Lock :size="14" aria-hidden="true" />Managed by the partner · read-only
                                </p>
                            </header>
                        </template>
                        <p v-if="partner.paused" class="wd-hint">Not shown while the partnership is paused.</p>
                        <EmptyState
                            v-else-if="!partner.profile"
                            title="No shared profile yet"
                            :description="`${partner.creator_name} hasn't added business contact details for the Brands they work with.`"
                        />
                        <DetailList v-else :items="profile" missing="Not shared">
                            <template v-if="partner.profile.contact_email" #contact_email>
                                <a :href="`mailto:${partner.profile.contact_email}`" class="wd-link bp-break">{{
                                    partner.profile.contact_email
                                }}</a>
                            </template>
                            <template v-if="website" #website_url>
                                <a
                                    :href="website"
                                    class="wd-link bp-break"
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    >{{ website.replace(/^https?:\/\//i, '')
                                    }}<ExternalLink :size="14" aria-hidden="true" class="bp-external" /><span
                                        class="wd-sr-only"
                                    >
                                        (opens in a new tab)</span
                                    ></a
                                >
                            </template>
                            <template v-if="partner.profile.description" #description>
                                <span class="whitespace-pre-line">{{ partner.profile.description }}</span>
                            </template>
                        </DetailList>
                    </ContentSection>
                    <details class="bp-explainer">
                        <summary>What this record includes</summary>
                        <ul>
                            <li>
                                Only what {{ partner.creator_name }} chooses to share with {{ workspace.name }}:
                                registered leads and an optional business profile.
                            </li>
                            <li>Their private pipeline, notes, values, reminders and other Brands are never shown.</li>
                            <li>
                                Last active is the latest of this partner signing in and a change to a lead they
                                registered with you.
                            </li>
                        </ul>
                    </details>
                </div>
            </div>
        </main>
    </BrandChrome>
</template>

<style scoped>
/* Record header, breadcrumb and columns: shared wd-record styles in portal-theme.css. */
.bp-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 24px;
    padding-top: 18px;
    border-top: 1px solid var(--wd-divider);
}
.bp-figures {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.bp-scope {
    flex: 1 1 260px;
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.bp-notice {
    margin: 0;
}

.bp-leads {
    gap: 14px;
    min-width: 0;
}
.bp-count {
    display: inline-block;
    margin-left: 8px;
    padding: 0 8px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-body);
    font-family: var(--wd-font-body);
    font-size: 13px;
    line-height: 22px;
    vertical-align: 3px;
}
.bp-owner {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.bp-break {
    overflow-wrap: anywhere;
}
.bp-external {
    display: inline;
    margin-left: 4px;
    vertical-align: -2px;
}
.bp-explainer {
    border: 1px solid var(--wd-line);
    border-radius: 16px;
    background: var(--wd-surface);
}
.bp-explainer summary {
    display: flex;
    align-items: center;
    min-height: 48px;
    padding: 0 20px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}
.bp-explainer ul {
    margin: 0;
    padding: 0 20px 16px 36px;
    color: var(--wd-body);
    font-size: 13px;
}
.bp-explainer li + li {
    margin-top: 6px;
}

@media (max-width: 599px) {
    .bp-figures {
        display: grid;
        width: 100%;
    }
}
/* Profile links keep the 44px touch target but wrap as text, so the new-tab icon follows the address. */
@media (max-width: 599px), (pointer: coarse) {
    .wd-record-aside :deep(.wd-detail-list a) {
        display: inline-block;
        padding-block: 11px;
    }
}
</style>
