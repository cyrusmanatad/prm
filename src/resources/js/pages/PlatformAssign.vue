<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import BackLink from '../features/admin/BackLink.vue';
import UsageTable from '../features/admin/UsageTable.vue';
import { confirmAction } from '../composables/useConfirmation';
import { useValidationFocus } from '../composables/useValidationFocus';
import { recordResourceLabel } from '../features/entitlements/resources';
import { dateTime, entitlementStates, localToIso, localZone } from '../features/admin/format';
import { quotaResources, type Entitlement } from '../features/admin/types';

const props = defineProps<{ workspace: { id: string; name: string }; entitlement: Entitlement }>();
const back = computed(() => `/admin/workspaces/${props.workspace.id}?tab=entitlements`);
const state = computed(() => entitlementStates[props.entitlement.state]);
const root = ref<HTMLElement>();
const form = useForm({
    workspace: props.workspace.id,
    expected_version: props.entitlement.assignment?.version ?? 0,
    name: props.entitlement.plan?.name ?? '',
    starts_at: '',
    ends_at: '',
    reason: '',
    quotas: Object.fromEntries(quotaResources.map((key) => [key, ''])) as Record<string, string>,
});
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
const zone = localZone();
// Limits below what the workspace already holds block further creates; say so before the operator confirms.
const reduced = computed(() =>
    props.entitlement.usage.filter(
        (row) => form.quotas[row.resource] !== '' && Number(form.quotas[row.resource]) < row.used,
    ),
);
const future = computed(() => !!form.starts_at && new Date(form.starts_at).getTime() > Date.now());
function assign(event: SubmitEvent) {
    if (form.processing) return;
    const consequences = [
        future.value
            ? 'It starts in the future: new records are blocked until then, and the current assignment stops applying now.'
            : 'It takes effect immediately.',
        reduced.value.length
            ? `Lower limits block new ${reduced.value.map((row) => recordResourceLabel(row.resource).toLowerCase()).join(', ')}; existing records are kept.`
            : '',
        'Existing professional schedules, pending notices and approvals for this workspace will be cancelled.',
    ]
        .filter(Boolean)
        .join(' ');
    confirmAction(
        'Record subscription assignment',
        `Replace the selected assignment for ${props.workspace.name} with ${form.name}, from ${dateTime(localToIso(form.starts_at))} to ${dateTime(localToIso(form.ends_at))}? ${consequences}`,
        () =>
            form
                .transform((data) => ({
                    ...data,
                    starts_at: localToIso(data.starts_at),
                    ends_at: localToIso(data.ends_at),
                }))
                .post('/admin/assignments'),
        event,
    );
}
</script>

<template>
    <AdminShell
        :title="entitlement.assignment ? 'Replace assignment' : 'Record assignment'"
        :description="`For ${workspace.name}. This records an immutable plan version and assignment. No payment is collected.`"
        eyebrow="Entitlements"
    >
        <template #back><BackLink :href="back" :label="`Back to ${workspace.name}`" /></template>
        <ContentSection title="Current state">
            <p>
                <StatusBadge :label="state?.label ?? entitlement.state" :tone="state?.tone ?? 'neutral'" />
            </p>
            <p v-if="entitlement.assignment && entitlement.plan">
                {{ entitlement.plan.name }} v{{ entitlement.plan.version }}, assignment version
                {{ entitlement.assignment.version }}: {{ dateTime(entitlement.assignment.starts_at) }} to
                {{ dateTime(entitlement.assignment.ends_at) }}.
            </p>
            <p v-else>{{ state?.meaning }}</p>
            <UsageTable :entitlement="entitlement" />
        </ContentSection>
        <ContentSection title="New assignment">
            <form ref="root" @submit.prevent="assign">
                <p v-if="form.errors.expected_version" class="wd-banner" role="alert">
                    {{ form.errors.expected_version }} Your entries are kept on this page; copy what you need before
                    reloading.
                    <Link :href="`/admin/assignments/create?workspace=${workspace.id}`" class="wd-link"
                        >Reload the current assignment</Link
                    >
                </p>
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Subscription assignment</legend>
                    <FormField
                        id="assign-name"
                        v-model="form.name"
                        label="Plan name"
                        :error="form.errors.name"
                        :maxlength="120"
                        hint="Reusing a name creates its next version with the limits below."
                    />
                    <FormField
                        id="assign-start"
                        v-model="form.starts_at"
                        type="datetime-local"
                        label="Starts at"
                        :hint="`Your local time (${zone}). A future start blocks new records until then.`"
                        :error="form.errors.starts_at"
                    />
                    <FormField
                        id="assign-end"
                        v-model="form.ends_at"
                        type="datetime-local"
                        label="Ends at"
                        :hint="`Your local time (${zone}). Access does not renew or fall back when this passes.`"
                        :error="form.errors.ends_at"
                    />
                    <div class="adm-quota-grid">
                        <FormField
                            v-for="resource in quotaResources"
                            :id="`quota-${resource}`"
                            :key="resource"
                            v-model="form.quotas[resource]"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            :label="`${recordResourceLabel(resource)} limit`"
                            :error="form.errors[`quotas.${resource}` as keyof typeof form.errors]"
                        />
                    </div>
                    <p v-if="reduced.length" class="wd-warning" role="status">
                        {{ reduced.length === 1 ? 'One limit is' : `${reduced.length} limits are` }} below what this
                        workspace already holds. Existing records are kept, and new ones are blocked for:
                        {{ reduced.map((row) => recordResourceLabel(row.resource)).join(', ') }}.
                    </p>
                    <FormField
                        id="assign-reason"
                        v-model="form.reason"
                        label="Reason"
                        :maxlength="240"
                        :error="form.errors.reason"
                        hint="Recorded in the administration audit."
                    />
                    <Button type="submit" class="wd-button wd-button-primary">Record assignment</Button>
                </fieldset>
            </form>
        </ContentSection>
    </AdminShell>
</template>
