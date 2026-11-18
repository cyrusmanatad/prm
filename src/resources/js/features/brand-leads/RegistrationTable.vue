<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateTime } from '../admin/format';
import { stageLabel } from '../portals/activity';
import type { RegistrationRow, StageOption } from '../portals/types';

withDefaults(
    defineProps<{
        caption: string;
        rows: RegistrationRow[];
        stages: StageOption[];
        /** Address of a lead's detail screen, carrying the list state to return to. */
        detailHref: (row: RegistrationRow) => string;
        /** The Partner record already names the Partner. */
        showPartner?: boolean;
    }>(),
    { showPartner: true },
);
const columns: DataColumn[] = [
    { key: 'display_name', label: 'Lead' },
    { key: 'partner_name', label: 'Partner' },
    { key: 'stage', label: 'Current stage' },
    { key: 'registered_at', label: 'Registered' },
    { key: 'shared_updated_at', label: 'Last shared update' },
];
</script>

<template>
    <DataTable
        :caption="caption"
        :columns="showPartner ? columns : columns.filter((column) => column.key !== 'partner_name')"
        :rows="rows"
        :row-key="(row) => row.id"
    >
        <template #cell-display_name="{ row }">
            <Link :href="detailHref(row)">{{ row.display_name }}</Link>
        </template>
        <template #cell-partner_name="{ row }">
            <Link :href="`/brand/partners/${row.partnership_id}`">{{ row.partner_name }}</Link>
        </template>
        <template #cell-stage="{ row }"><StatusBadge :label="stageLabel(stages, row.stage)" /></template>
        <template #cell-registered_at="{ row }">{{ dateTime(row.registered_at) }}</template>
        <template #cell-shared_updated_at="{ row }">{{ dateTime(row.shared_updated_at) }}</template>
    </DataTable>
</template>
