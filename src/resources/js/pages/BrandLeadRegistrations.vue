<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import { useListFilters } from '../composables/useListFilters';
import { query } from '../features/admin/format';
import RegistrationDrawer from '../features/brand-leads/RegistrationDrawer.vue';
import RegistrationFilterBar from '../features/brand-leads/RegistrationFilterBar.vue';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import type {
    BrandWorkspace,
    PageMeta,
    RegistrationFacets,
    RegistrationDetail,
    RegistrationFilters,
    RegistrationRow,
    RegistrationSummary,
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
    facets: RegistrationFacets;
    summary?: RegistrationSummary;
    selected?: RegistrationDetail | null;
}>();
const path = '/brand/lead-registrations';
const { pending, apply, href } = useListFilters(path, () => props.filters);

const partnerOptions = computed(() => [
    { value: '', label: 'All partners', count: props.facets.partnerTotal },
    ...props.partners.map((row) => ({
        value: row.id,
        label: row.creator_name,
        count: props.facets.partners[row.id] ?? 0,
    })),
]);
const pageSizes = ['10', '25', '50'];
const perPage = computed(() => props.filters.per_page || String(props.pagination.perPage));
const filtered = computed(
    () => !!(props.filters.q || props.filters.partner || props.filters.stage || props.filters.window),
);

// A registration opens in a drawer over the list; the address keeps the list state and the open registration.
const detailHref = (row: RegistrationRow) =>
    `${path}${query({ ...props.filters, page: props.pagination.page, registration: row.id })}`;
function closeDrawer(): void {
    router.get(href(props.pagination.page), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Lead registrations" />
    <BrandChrome :workspace-name="workspace.name" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page rg wd-portal-page flex flex-col gap-3.5 pt-6 pb-12">
            <header>
                <h1>Lead registrations</h1>
                <p class="rg-sub">
                    Leads your partners chose to share with {{ workspace.name }}. Their private notes, estimates and
                    other prospects are never shown.
                </p>
            </header>
            <ul v-if="summary" class="rg-stats" aria-label="Registration summary">
                <li>
                    <span>Registrations</span><strong>{{ summary.total }}</strong>
                </li>
                <li>
                    <span>Last 30 days</span><strong>{{ summary.recent }}</strong>
                </li>
                <li>
                    <span>Partners registering</span><strong>{{ summary.partners }}</strong>
                </li>
                <li>
                    <span>Updated this week</span><strong>{{ summary.updatedWeek }}</strong>
                </li>
            </ul>
            <section class="rg-card" aria-labelledby="registered-leads-heading">
                <header class="rg-card-head">
                    <h2 id="registered-leads-heading">Registered leads</h2>
                    <span class="rg-count">{{ facets.stageTotal }}</span>
                    <p class="rg-note">
                        <Info :size="14" aria-hidden="true" />Counts are registrations, not sales, revenue or estimates.
                    </p>
                </header>
                <RegistrationFilterBar
                    class="rg-filter-bar"
                    label="Filter lead registrations"
                    :filters="filters"
                    :stages="stages"
                    :stage-counts="facets.stages"
                    :stage-total="facets.stageTotal"
                    :stage-href="(stage) => href(1, { stage })"
                    :partners="partnerOptions"
                    :pending="pending"
                    @apply="apply"
                />
                <RegistrationTable
                    v-if="registrations.length"
                    :class="{ 'is-pending': pending }"
                    v-bind="{ 'aria-busy': pending ? 'true' : undefined }"
                    caption="Lead registrations"
                    :rows="registrations"
                    :stages="stages"
                    :detail-href="detailHref"
                    :sort="filters.sort"
                    :sort-href="(sort) => href(1, { sort })"
                />
                <EmptyState
                    v-else
                    class="rg-empty"
                    :title="filtered ? 'No registrations match these filters' : 'No leads registered yet'"
                    :description="
                        filtered
                            ? 'Clear the filters to see every lead your partners have registered with you.'
                            : 'A lead appears here after a partner registers it with you.'
                    "
                />
                <Pagination
                    class="rg-pager"
                    label="Lead registrations pages"
                    noun="registrations"
                    numbered
                    :page="pagination.page"
                    :per-page="pagination.perPage"
                    :count="registrations.length"
                    :total="pagination.total"
                    :href-for="href"
                >
                    <div class="wd-page-size">
                        <label for="registration-per-page">Rows per page</label>
                        <select
                            id="registration-per-page"
                            :value="perPage"
                            @change="apply({ per_page: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                        </select>
                    </div>
                </Pagination>
            </section>
        </main>
        <RegistrationDrawer v-if="selected" :registration="selected" :stages="stages" @close="closeDrawer" />
    </BrandChrome>
</template>

<style scoped>
.rg {
    color: var(--wd-ink);
    font-size: 14px;
}
.rg h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.rg-sub {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.rg-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.rg-stats li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.rg-stats span {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.rg-stats strong {
    font-family: var(--wd-font-display);
    font-size: 20px;
    line-height: 1;
}
.rg-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.rg-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 12px 14px 8px;
}
.rg-card-head h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.rg-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 11.5px;
    font-weight: 700;
}
.rg-note {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 0 auto;
    color: var(--wd-muted);
    font-size: 12px;
}
/* The shared filter toolbar (RegistrationFilterBar) sits inside the card's side padding. */
.rg-filter-bar {
    padding: 0 14px 10px;
}
.rg-empty {
    margin: 0 14px 14px;
}
.rg-pager {
    padding: 8px 14px;
    border-top: 1px solid var(--wd-divider);
}
.rg :deep(.wd-registration-table) {
    border-top: 1px solid var(--wd-divider);
}
@media (max-width: 899px) {
    .rg-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 599px) {
    .rg h1 {
        font-size: 24px;
    }
    .rg-note {
        margin-left: 0;
        flex-basis: 100%;
    }
    .rg-stats {
        gap: 6px;
    }
    .rg-stats li {
        padding: 8px 12px;
    }
}
</style>
