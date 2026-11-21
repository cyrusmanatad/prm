<script setup lang="ts">
import { computed } from 'vue';
import { Download, Eye, Pencil, Send, Trash2 } from '@lucide/vue';
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../../components/FilterChips.vue';
import RowActionMenu, { type RowAction } from '../../components/RowActionMenu.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import {
    canSend,
    draftShortStatus,
    draftTone,
    handoffHref,
    isOpen,
    type DraftAction,
    type DraftFilter,
    type FollowUpDraft,
} from './follow-up-types';

const props = defineProps<{ drafts: FollowUpDraft[]; canWrite: boolean; busy: boolean }>();
const filter = defineModel<DraftFilter>('filter', { required: true });
const emit = defineEmits<{
    open: [draft: FollowUpDraft, edit?: boolean];
    act: [draft: FollowUpDraft, kind: DraftAction, source: Event | HTMLElement];
}>();

const matches = (draft: FollowUpDraft, value: DraftFilter) =>
    !value || (value === 'closed' ? !isOpen(draft) : draft.state === value);
const chips = computed<FilterChip[]>(() =>
    (
        [
            ['draft', 'Needs review'],
            ['approved', 'Approved'],
            ['closed', 'Closed'],
            ['', 'All'],
        ] as const
    ).map(([value, label]) => ({ value, label, count: props.drafts.filter((d) => matches(d, value)).length })),
);
const rows = computed(() => props.drafts.filter((draft) => matches(draft, filter.value)));
const columns: DataColumn[] = [
    { key: 'subject', label: 'Message' },
    { key: 'lead', label: 'Lead' },
    { key: 'recipient', label: 'To' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Actions' },
];
const emptyTitles: Record<string, string> = {
    draft: 'Nothing needs review',
    approved: 'No approved drafts',
    closed: 'No closed drafts',
};
const emptyTitle = computed(() => emptyTitles[filter.value] ?? 'No follow-up drafts yet');
function actions(draft: FollowUpDraft): RowAction[] {
    const list: RowAction[] = [{ label: 'Open', icon: Eye, select: () => emit('open', draft) }];
    if (!props.canWrite || !isOpen(draft)) return list;
    list.push({ label: 'Edit', icon: Pencil, select: () => emit('open', draft, true) });
    if (draft.state === 'approved')
        list.push({ label: 'Download approved message', icon: Download, href: handoffHref(draft), download: true });
    list.push({
        label: 'Discard',
        icon: Trash2,
        danger: true,
        separated: true,
        select: (trigger) => emit('act', draft, 'discard', trigger),
    });
    return list;
}
</script>

<template>
    <section class="rec-card" aria-labelledby="drafts-heading">
        <header class="rec-card-head">
            <h2 id="drafts-heading" tabindex="-1">Your follow-up drafts</h2>
            <span class="rec-count">{{ drafts.length }}</span>
            <p class="rec-note">Private to you · your latest 50</p>
        </header>
        <div class="rec-chips">
            <FilterChips
                label="Show drafts"
                :chips="chips"
                :current="filter"
                compact
                @select="filter = $event as DraftFilter"
            />
        </div>
        <DataTable
            v-if="rows.length"
            class="rec-table rec-drafts-table"
            caption="Your follow-up drafts"
            :columns="columns"
            :rows="rows"
            :row-key="(draft) => draft.id"
        >
            <template #cell-subject="{ row }">
                <button type="button" class="rec-row-title" @click="emit('open', row)">
                    {{ row.subject || 'Untitled follow-up' }}
                </button>
                <span class="rec-row-sub">{{ row.body }}</span>
            </template>
            <template #cell-lead="{ row }">{{ row.lead_name || '—' }}</template>
            <template #cell-recipient="{ row }"
                ><span class="rec-ellipsis">{{ row.recipient || 'No recipient yet' }}</span></template
            >
            <template #cell-status="{ row }"
                ><StatusBadge :label="draftShortStatus(row)" :tone="draftTone(row)" /><span class="rec-version"
                    >v{{ row.version }}</span
                ></template
            >
            <template #cell-actions="{ row }">
                <span class="rec-actions">
                    <button
                        v-if="canWrite && row.state === 'draft'"
                        type="button"
                        class="wd-table-button rec-primary"
                        :disabled="busy"
                        v-bind="{ 'aria-label': `Approve ${row.subject}` }"
                        @click="emit('act', row, 'approve', $event)"
                    >
                        Approve
                    </button>
                    <button
                        v-else-if="canWrite && canSend(row)"
                        type="button"
                        class="wd-table-button"
                        :disabled="busy"
                        v-bind="{ 'aria-label': `Send ${row.subject}` }"
                        @click="emit('act', row, 'send', $event)"
                    >
                        <Send :size="14" aria-hidden="true" />Send
                    </button>
                    <RowActionMenu
                        :label="`Actions for ${row.subject || 'untitled follow-up'}`"
                        :actions="actions(row)"
                        :disabled="busy"
                        trigger-class="rec-ghost"
                    />
                </span>
            </template>
        </DataTable>
        <EmptyState
            v-else
            :title="emptyTitle"
            :description="
                drafts.length
                    ? 'Choose another filter to see the rest.'
                    : 'Drafts you write or schedule appear here for review.'
            "
        />
        <footer class="rec-card-foot">
            Approval never sends email. Sending an approved message is a separate, confirmed step.
        </footer>
    </section>
</template>
