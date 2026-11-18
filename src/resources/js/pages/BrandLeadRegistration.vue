<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ContentSection from '../components/ContentSection.vue';
import DetailList, { type DetailItem } from '../components/DetailList.vue';
import StatusBadge from '../components/StatusBadge.vue';
import BackLink from '../features/admin/BackLink.vue';
import { dateTime, query } from '../features/admin/format';
import BrandChrome from '../features/portals/BrandChrome.vue';
import { stageLabel } from '../features/portals/activity';
import type { BrandWorkspace, RegistrationDetail, RegistrationFilters, StageOption } from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: BrandWorkspace;
    registration: RegistrationDetail;
    stages: StageOption[];
    back: { from: 'list' | 'partner'; page: number; filters: RegistrationFilters };
}>();
const partnerHref = computed(() => `/brand/partners/${props.registration.partnership_id}`);
// Returns to the list or Partner record the lead was opened from, with its filters and page.
const backLink = computed(() =>
    props.back.from === 'partner'
        ? { href: `${partnerHref.value}${query({ page: props.back.page })}`, label: props.registration.partner_name }
        : {
              href: `/brand/lead-registrations${query({ ...props.back.filters, page: props.back.page })}`,
              label: 'Lead registrations',
          },
);
const items = computed<DetailItem[]>(() => [
    { key: 'partner', label: 'Registered by' },
    { key: 'brand', label: 'Registered with', value: props.registration.brand_name },
    { key: 'stage', label: 'Current stage' },
    { key: 'registered', label: 'Registered', value: dateTime(props.registration.registered_at) },
    { key: 'updated', label: 'Last shared update', value: dateTime(props.registration.shared_updated_at) },
    { key: 'summary', label: 'Summary from the partner', value: props.registration.summary },
    { key: 'contact_name', label: 'Business contact', value: props.registration.contact_name },
    { key: 'contact_email', label: 'Business contact email', value: props.registration.contact_email },
]);
const missing = computed(() => (props.registration.share_contact ? 'Not provided' : 'Not shared by the partner'));
</script>

<template>
    <Head :title="registration.display_name" />
    <BrandChrome
        current="registrations"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="blv-page mx-auto flex max-w-[960px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <BackLink :href="backLink.href" :label="`Back to ${backLink.label}`" />
                <h1 class="mt-2 font-display text-[32px] font-bold [overflow-wrap:anywhere]">
                    {{ registration.display_name }}
                </h1>
                <p class="mt-1.5 text-[15px] text-ink-soft">
                    Registered lead. This is the information {{ registration.partner_name }} chose to share with
                    {{ registration.brand_name }}.
                </p>
            </div>
            <ContentSection
                title="Shared lead information"
                description="Read-only. The partner manages this lead and its stage; changes they make appear here."
            >
                <DetailList :items="items" :missing="missing">
                    <template #partner
                        ><Link :href="partnerHref" class="wd-link">{{ registration.partner_name }}</Link></template
                    >
                    <template #stage><StatusBadge :label="stageLabel(stages, registration.stage)" /></template>
                    <template #summary>
                        <span class="whitespace-pre-line">{{ registration.summary || 'No summary provided' }}</span>
                    </template>
                    <template v-if="registration.contact_email" #contact_email>
                        <a :href="`mailto:${registration.contact_email}`" class="wd-link">{{
                            registration.contact_email
                        }}</a>
                    </template>
                </DetailList>
                <p class="wd-hint">
                    The partner's private notes, reminders, estimates and other prospects are not shared and cannot be
                    requested here.
                </p>
            </ContentSection>
        </main>
    </BrandChrome>
</template>
