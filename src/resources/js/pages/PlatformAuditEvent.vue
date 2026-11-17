<script setup lang="ts">
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import DetailList from '../components/DetailList.vue';
import StatusBadge from '../components/StatusBadge.vue';
import BackLink from '../features/admin/BackLink.vue';
import { auditOutcomes, auditSources, dateTime } from '../features/admin/format';

defineProps<{
    event: {
        source: string;
        id: string;
        occurred_at: string;
        actor: string;
        actor_email: string | null;
        operator: string;
        event: string;
        target: string;
        reason: string;
        outcome: string | null;
        workspace: string | null;
    };
}>();
</script>

<template>
    <AdminShell
        :title="event.event"
        :description="`${auditSources[event.source] ?? event.source} event, ${dateTime(event.occurred_at)}`"
        eyebrow="Audit event"
    >
        <template #back><BackLink href="/admin/audit" label="All audit events" /></template>
        <template #actions>
            <StatusBadge
                v-if="event.outcome"
                :label="auditOutcomes[event.outcome]?.label ?? event.outcome"
                :tone="auditOutcomes[event.outcome]?.tone ?? 'neutral'"
            />
        </template>
        <ContentSection title="Event">
            <DetailList
                missing="Not recorded in this event source"
                :items="[
                    { key: 'id', label: 'Event ID' },
                    { key: 'source', label: 'Source', value: auditSources[event.source] ?? event.source },
                    { key: 'action', label: 'Action', value: event.event },
                    { key: 'actor', label: 'Actor', value: event.actor },
                    { key: 'email', label: 'Actor email', value: event.actor_email },
                    { key: 'target', label: 'Target reference', value: event.target },
                    { key: 'workspace', label: 'Workspace', value: event.workspace },
                    { key: 'reason', label: 'Reason', value: event.reason },
                    {
                        key: 'outcome',
                        label: 'Outcome',
                        value: event.outcome ? (auditOutcomes[event.outcome]?.label ?? event.outcome) : null,
                    },
                    { key: 'occurred', label: 'Occurred', value: dateTime(event.occurred_at) },
                    { key: 'exact', label: 'Exact timestamp', value: event.occurred_at },
                    { key: 'changes', label: 'Changed fields', value: null },
                ]"
            >
                <template #id><CopyReference :value="event.id" label="event ID" /></template>
            </DetailList>
            <p v-if="event.source === 'local-pilot'" class="wd-hint">
                Local pilot events were recorded by a command-line operator label, which is not an authenticated
                platform identity.
            </p>
            <p class="wd-hint">Audit events are read-only. Record contents are never copied into the audit.</p>
        </ContentSection>
    </AdminShell>
</template>
