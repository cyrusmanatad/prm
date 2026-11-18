<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterBar, { type AppliedFilter } from '../components/FilterBar.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import { useListFilters } from '../composables/useListFilters';
import { query } from '../features/admin/format';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import { stageLabel } from '../features/portals/activity';
import type {
    BrandWorkspace,
    PageMeta,
    RegistrationFilters,
    RegistrationRow,
    StageOption,
} from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: BrandWorkspace;
    registrations: RegistrationRow[];
    pagination: PageMeta;
    filters: RegistrationFilters;
    partners: { id: string; creator_name: string }[];
    stages: StageOption[];
}>();
const path = '/brand/lead-registrations';
const { draft, pending, submit, clear, href } = useListFilters(path, () => props.filters);
const partnerOptions = computed(() => [
    { value: '', label: 'All partners' },
    ...props.partners.map((row) => ({ value: row.id, label: row.creator_name })),
]);
const stageOptions = computed(() => [{ value: '', label: 'All stages' }, ...props.stages]);
const windowOptions = [
    { value: '', label: 'Any time' },
    { value: '30d', label: 'Last 30 days' },
];
const applied = computed<AppliedFilter[]>(() =>
    [
        props.filters.q && { label: 'Lead name contains', value: props.filters.q },
        props.filters.partner && {
            label: 'Partner',
            // A partner that is not one of this Brand's active partners simply has no registrations to show.
            value:
                props.partners.find((row) => row.id === props.filters.partner)?.creator_name ?? 'Not an active partner',
        },
        props.filters.stage && { label: 'Stage', value: stageLabel(props.stages, props.filters.stage) },
        props.filters.window && { label: 'Registered', value: 'Last 30 days' },
    ].filter((item): item is AppliedFilter => !!item),
);
const detailHref = (row: RegistrationRow) =>
    `${path}/${row.id}${query({ ...props.filters, page: props.pagination.page })}`;
</script>

<template>
    <Head title="Lead registrations" />
    <BrandChrome
        current="registrations"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="blv-page mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <h1 class="font-display text-[32px] font-bold">Lead registrations</h1>
                <p class="mt-1.5 max-w-[760px] text-[15px] text-ink-soft">
                    Leads your partners have chosen to register with {{ workspace.name }}, with each lead's current
                    stage. Partners' other prospects, private notes and estimates are never shown here.
                </p>
            </div>
            <ContentSection
                title="Registered leads"
                description="Counts are registrations. They are not sales, revenue or estimates."
            >
                <FilterBar
                    label="Filter lead registrations"
                    :applied="applied"
                    :pending="pending"
                    @submit="submit"
                    @clear="clear"
                >
                    <FormField
                        id="registration-search"
                        v-model="draft.q"
                        label="Lead name"
                        :required="false"
                        :maxlength="160"
                    />
                    <SelectField
                        id="registration-partner"
                        v-model="draft.partner"
                        label="Partner"
                        :options="partnerOptions"
                    />
                    <SelectField
                        id="registration-stage"
                        v-model="draft.stage"
                        label="Stage"
                        :options="stageOptions"
                        :searchable="false"
                    />
                    <SelectField
                        id="registration-window"
                        v-model="draft.window"
                        label="Registered"
                        :options="windowOptions"
                        :searchable="false"
                    />
                </FilterBar>
                <RegistrationTable
                    v-if="registrations.length"
                    class="mt-4"
                    caption="Lead registrations"
                    :rows="registrations"
                    :stages="stages"
                    :detail-href="detailHref"
                />
                <EmptyState
                    v-else
                    class="mt-4"
                    :title="applied.length ? 'No registrations match these filters' : 'No leads registered yet'"
                    :description="
                        applied.length
                            ? 'Clear the filters to see every lead your partners have registered with you.'
                            : 'A lead appears here after a partner registers it with you.'
                    "
                />
                <Pagination
                    class="mt-4"
                    label="Lead registrations pages"
                    noun="registrations"
                    :page="pagination.page"
                    :per-page="pagination.perPage"
                    :count="registrations.length"
                    :total="pagination.total"
                    :href-for="href"
                />
            </ContentSection>
        </main>
    </BrandChrome>
</template>
