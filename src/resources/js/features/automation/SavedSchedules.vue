<script setup lang="ts">
import DataTable, { type DataColumn } from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { kindLabel, weekdays, type Schedule } from './types';

defineProps<{ schedules: Schedule[]; canWrite: boolean; busy: boolean; error?: string; reload: string }>();
defineEmits<{ edit: [schedule: Schedule, trigger: HTMLElement]; withdraw: [schedule: Schedule, event: Event] }>();
const columns: DataColumn[] = [
    { key: 'name', label: 'Schedule' },
    { key: 'kind', label: 'Type' },
    { key: 'next', label: 'Next' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: 'Actions' },
];
function due(schedule: Schedule): string {
    return schedule.enabled && schedule.next_due_at
        ? new Intl.DateTimeFormat('en', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: schedule.timezone,
          }).format(new Date(schedule.next_due_at))
        : '—';
}
function detail(schedule: Schedule): string {
    if (schedule.kind === 'weekly_digest')
        return `Every ${weekdays[(schedule.weekday ?? 1) - 1]} at ${schedule.local_time ?? ''}`.trim();
    if (schedule.kind === 'reminder') return schedule.subject_label ?? 'Reminder no longer available';
    return `${schedule.subject_lead ?? 'Lead no longer available'} · prepares a private draft`;
}
</script>

<template>
    <section class="auto-card" aria-labelledby="schedules-heading">
        <header class="auto-card-head">
            <h2 id="schedules-heading">Your schedules</h2>
            <span class="auto-count">{{ schedules.length }}</span>
            <p class="auto-note">Your latest 100 · disabled schedules keep their history</p>
        </header>
        <div v-if="error" class="wd-banner auto-banner" role="alert">
            <p>{{ error }}</p>
            <a class="wd-link" :href="reload">Reload schedules</a>
        </div>
        <DataTable
            v-if="schedules.length"
            class="auto-table auto-schedules-table"
            caption="Your schedules"
            :columns="columns"
            :rows="schedules"
            :row-key="(schedule) => schedule.id"
        >
            <template #cell-name="{ row }">
                <span class="auto-row-name">{{ row.name }}</span>
                <span class="auto-row-sub">{{ detail(row) }}</span>
            </template>
            <template #cell-kind="{ row }">{{ kindLabel(row.kind) }}</template>
            <template #cell-next="{ row }"
                ><span :title="row.timezone">{{ due(row) }}</span></template
            >
            <template #cell-status="{ row }"
                ><StatusBadge
                    :label="row.enabled ? 'Consent active' : 'Disabled'"
                    :tone="row.enabled ? 'success' : 'neutral'"
            /></template>
            <template #cell-actions="{ row }">
                <span v-if="canWrite" class="auto-actions">
                    <button
                        v-if="row.kind === 'weekly_digest'"
                        type="button"
                        class="wd-table-button"
                        :disabled="busy"
                        v-bind="{ 'aria-label': `Edit ${row.name}` }"
                        @click="$emit('edit', row, $event.currentTarget as HTMLElement)"
                    >
                        Edit
                    </button>
                    <button
                        v-if="row.enabled"
                        type="button"
                        class="wd-table-button auto-danger"
                        :disabled="busy || !!error"
                        v-bind="{ 'aria-label': `Withdraw consent for ${row.name}` }"
                        @click="$emit('withdraw', row, $event)"
                    >
                        Withdraw
                    </button>
                </span>
            </template>
        </DataTable>
        <EmptyState
            v-else
            title="No schedules yet"
            description="Turn on a notice in Preferences, then add a schedule with New schedule."
        />
        <footer class="auto-card-foot">
            Withdrawing consent disables a schedule and cancels its pending work. A send already reserved may still
            arrive.
        </footer>
    </section>
</template>
