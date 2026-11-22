<script setup lang="ts">
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import StatusBadge from '../components/StatusBadge.vue';
import AdminCard from '../features/admin/AdminCard.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
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
    <AdminShell :title="event.event" compact>
        <template #back><BackLink href="/admin/audit" label="All audit events" /></template>
        <template #description
            ><span class="adm-meta"
                ><span
                    >{{ auditSources[event.source] ?? event.source }} event · {{ dateTime(event.occurred_at) }} · Event
                    ID</span
                ><CopyReference :value="event.id" label="event ID" /></span
        ></template>
        <template #actions>
            <StatusBadge
                v-if="event.outcome"
                :label="auditOutcomes[event.outcome]?.label ?? event.outcome"
                :tone="auditOutcomes[event.outcome]?.tone ?? 'neutral'"
            />
        </template>
        <AdminCard title="Event" note="Read-only · record contents are never copied into the audit">
            <FactList
                missing="Not recorded in this event source"
                :items="[
                    { key: 'source', label: 'Source', value: auditSources[event.source] ?? event.source },
                    { key: 'action', label: 'Action', value: event.event },
                    { key: 'actor', label: 'Actor', value: event.actor },
                    { key: 'email', label: 'Actor email', value: event.actor_email },
                    { key: 'target', label: 'Target reference', value: event.target },
                    { key: 'workspace', label: 'Workspace', value: event.workspace },
                    {
                        key: 'outcome',
                        label: 'Outcome',
                        value: event.outcome ? (auditOutcomes[event.outcome]?.label ?? event.outcome) : null,
                    },
                    { key: 'occurred', label: 'Occurred', value: dateTime(event.occurred_at) },
                    { key: 'exact', label: 'Exact timestamp', value: event.occurred_at },
                    { key: 'changes', label: 'Changed fields', value: null },
                    { key: 'reason', label: 'Reason', value: event.reason, wide: true },
                ]"
            />
            <p v-if="event.source === 'local-pilot'" class="rec-fine">
                Local pilot events were recorded by a command-line operator label, which is not an authenticated
                platform identity.
            </p>
        </AdminCard>
    </AdminShell>
</template>
