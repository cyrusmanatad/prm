<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import { dateTime } from '../admin/format';
import { relativeTime, stageLabel } from '../portals/activity';
import type { RegistrationRow, StageOption } from '../portals/types';
import StageBadge from './StageBadge.vue';

const props = withDefaults(
    defineProps<{
        caption: string;
        rows: RegistrationRow[];
        stages: StageOption[];
        /** Address of a lead's detail screen, carrying the list state to return to. */
        detailHref: (row: RegistrationRow) => string;
        /** The Partner record already names the Partner. */
        showPartner?: boolean;
        /** Applied sort key; '' is newest registration first. */
        sort?: string;
        /** Address of the list in another sort order. Without it the columns are not sortable. */
        sortHref?: (sort: string) => string;
    }>(),
    { showPartner: true, sort: '' },
);

type Sortable = 'display_name' | 'registered_at';
/** Ascending and descending keys of each sortable column. */
const sortKeys: Record<Sortable, { asc: string; desc: string }> = {
    display_name: { asc: 'lead_asc', desc: 'lead_desc' },
    registered_at: { asc: 'registered_asc', desc: '' },
};
function sortState(key: Sortable): 'ascending' | 'descending' | 'none' {
    if (props.sort === sortKeys[key].asc) return 'ascending';
    if (props.sort === sortKeys[key].desc) return 'descending';
    return 'none';
}
/** A first click sorts names A–Z and dates newest first; the next click reverses. */
function nextSort(key: Sortable): string {
    const state = sortState(key);
    if (key === 'display_name') return state === 'ascending' ? sortKeys[key].desc : sortKeys[key].asc;
    return state === 'descending' ? sortKeys[key].asc : sortKeys[key].desc;
}
function sortLabel(key: Sortable, label: string): string {
    const next = nextSort(key) === sortKeys[key].asc ? 'ascending' : 'descending';
    return `${label}, sort ${next}`;
}

const columns = computed<DataColumn[]>(() => {
    const sortable = (key: Sortable) => (props.sortHref ? { sort: sortState(key) } : {});
    return [
        { key: 'display_name', label: 'Lead', ...sortable('display_name') },
        { key: 'partner_name', label: 'Partner' },
        { key: 'stage', label: 'Stage' },
        { key: 'registered_at', label: 'Registered', ...sortable('registered_at') },
        { key: 'shared_updated_at', label: 'Last update' },
    ].filter((column) => props.showPartner || column.key !== 'partner_name');
});

/** Short exact time for a list row, with the year only when it is not the current one. */
function shortDate(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return dateTime(value);
    const sameYear = date.getFullYear() === new Date().getFullYear();
    return date.toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        ...(sameYear ? {} : { year: 'numeric' }),
        hour: '2-digit',
        minute: '2-digit',
    });
}
/** The shared record has changed since it was registered. */
const updated = (row: RegistrationRow) =>
    new Date(row.shared_updated_at).getTime() > new Date(row.registered_at).getTime();
</script>

<template>
    <DataTable
        class="wd-registration-table"
        :caption="caption"
        :columns="columns"
        :rows="rows"
        :row-key="(row) => row.id"
    >
        <template
            v-for="key in ['display_name', 'registered_at'] as Sortable[]"
            :key="key"
            #[`head-${key}`]="{ column }"
        >
            <Link
                v-if="sortHref"
                class="wd-sort-link"
                :class="{ 'is-sorted': column.sort !== 'none' }"
                :href="sortHref(nextSort(key))"
                v-bind="{ 'aria-label': sortLabel(key, column.label) }"
                preserve-scroll
                >{{ column.label
                }}<component
                    :is="column.sort === 'ascending' ? ArrowUp : column.sort === 'descending' ? ArrowDown : ArrowUpDown"
                    :size="15"
                    aria-hidden="true"
            /></Link>
            <template v-else>{{ column.label }}</template>
        </template>
        <template #cell-display_name="{ row }">
            <Link class="wd-registration-lead" :href="detailHref(row)" v-bind="{ title: row.display_name }">{{
                row.display_name
            }}</Link>
        </template>
        <template #cell-partner_name="{ row }">
            <Link class="wd-registration-partner" :href="`/brand/partners/${row.partnership_id}`">{{
                row.partner_name
            }}</Link>
        </template>
        <template #cell-stage="{ row }">
            <StageBadge :stage="row.stage" :label="stageLabel(stages, row.stage)" />
        </template>
        <template #cell-registered_at="{ row }">
            <time :datetime="row.registered_at" :title="dateTime(row.registered_at)">
                <span class="wd-registration-when">{{ relativeTime(row.registered_at) }}</span>
                <span class="wd-data-secondary">{{ shortDate(row.registered_at) }}</span>
            </time>
        </template>
        <template #cell-shared_updated_at="{ row }">
            <time v-if="updated(row)" :datetime="row.shared_updated_at" :title="dateTime(row.shared_updated_at)">
                <span class="wd-registration-when">{{ relativeTime(row.shared_updated_at) }}</span>
                <span class="wd-data-secondary">{{ shortDate(row.shared_updated_at) }}</span>
            </time>
            <span v-else class="wd-registration-none">No updates yet</span>
        </template>
    </DataTable>
</template>
