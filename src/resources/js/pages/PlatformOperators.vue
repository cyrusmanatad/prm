<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ActionGroup from '../components/ActionGroup.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
import { useValidationFocus } from '../composables/useValidationFocus';
import { capabilityLabels, capabilityNames } from '../features/admin/format';

type Operator = {
    user_id: number;
    name: string;
    email: string;
    verified: boolean;
    permissions: string[];
    version: number;
    revoked_at: string | null;
    mfa_ready: boolean;
    self: boolean;
    last_owner: boolean;
    suspended: boolean;
};
type Candidate = { id: number; name: string; email: string; verified: boolean };
type Mode = 'grant' | 'change' | 'revoke';
const props = defineProps<{ operators: Operator[]; capabilities: string[]; candidate: Candidate | null }>();

const existing = computed(() => props.operators.find((row) => row.user_id === props.candidate?.id));
const selected = ref<{ mode: Mode; id: number; name: string; email: string } | null>(null);
const section = ref<HTMLElement>();
const root = ref<HTMLElement>();
const form = useForm({
    user: 0,
    expected_version: 0,
    revoke: false,
    capabilities: [] as string[],
    reason: '',
    password: '',
    code: '',
});
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
async function choose(mode: Mode, target: { id: number; name: string; email: string }, operator?: Operator) {
    selected.value = { mode, ...target };
    form.clearErrors();
    form.user = target.id;
    form.expected_version = operator?.version ?? 0;
    form.revoke = mode === 'revoke';
    form.capabilities = mode === 'change' && operator ? [...operator.permissions] : [];
    form.reason = '';
    form.password = '';
    form.code = '';
    await nextTick();
    section.value?.scrollIntoView({ block: 'start' });
    section.value?.querySelector<HTMLElement>('input')?.focus();
}
// Arriving from an account page: start the form for that identity when it can receive a grant.
if (props.candidate?.verified && (!existing.value || existing.value.revoked_at))
    void choose('grant', props.candidate, existing.value);

const owner = computed(() => form.capabilities.includes('platform.owner'));
const current = computed(() => props.operators.find((row) => row.user_id === selected.value?.id));
const removed = computed(() =>
    selected.value?.mode === 'change' && current.value
        ? current.value.permissions.filter((key) => !owner.value && !form.capabilities.includes(key))
        : [],
);
const labels = capabilityNames;
function submit(event: SubmitEvent) {
    if (form.processing || !selected.value) return;
    const who = `${selected.value.name} (${selected.value.email})`;
    const signOut = 'Their current sessions are signed out and they must verify again.';
    const [title, description, tone] =
        selected.value.mode === 'revoke'
            ? ([
                  'Revoke platform access',
                  `Revoke all platform access for ${who}? They can no longer open administration. ${signOut} Their workspace memberships are not affected.`,
                  'danger',
              ] as const)
            : removed.value.length
              ? ([
                    'Reduce platform access',
                    `Remove ${labels(removed.value)} from ${who}? They keep: ${labels(form.capabilities)}. ${signOut}`,
                    'danger',
                ] as const)
              : ([
                    'Record platform access',
                    `Give ${who} these capabilities: ${labels(form.capabilities)}? ${owner.value ? 'Platform owner includes every capability and managing other operators. ' : ''}${signOut}`,
                    'default',
                ] as const);
    confirmAction(
        title,
        description,
        () =>
            form.post('/admin/operators', {
                preserveScroll: true,
                onSuccess: () => (selected.value = null),
                onFinish: () => form.reset('password', 'code'),
            }),
        event,
        { tone },
    );
}
const columns = [
    { key: 'name', label: 'Operator' },
    { key: 'permissions', label: 'Capabilities' },
    { key: 'state', label: 'Grant' },
    { key: 'mfa', label: 'Authenticator' },
    { key: 'actions', label: 'Actions' },
];
</script>

<template>
    <AdminShell
        title="Platform access"
        description="Who can open administration, and with which capabilities. A workspace role never grants platform access, and platform access never opens private workspace records."
        eyebrow="Governance"
    >
        <ContentSection title="Operators">
            <EmptyState v-if="!operators.length" title="No platform grants exist" />
            <DataTable v-else caption="Operators" :columns="columns" :rows="operators" :row-key="(row) => row.user_id">
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/accounts/${row.user_id}?tab=security`">{{ row.name }}</Link
                    ><span class="wd-data-secondary">{{ row.email }}</span
                    ><span v-if="row.self" class="wd-data-secondary">This is you</span></template
                >
                <template #cell-permissions="{ row }">{{ labels(row.permissions) }}</template>
                <template #cell-state="{ row }"
                    ><StatusBadge v-if="row.suspended" label="Account suspended" tone="danger" /><StatusBadge
                        v-else-if="!row.revoked_at"
                        label="Active"
                        tone="success" /><StatusBadge v-else label="Revoked"
                /></template>
                <template #cell-mfa="{ row }"
                    ><StatusBadge
                        :label="row.mfa_ready ? 'Ready' : 'Not enrolled'"
                        :tone="row.mfa_ready ? 'success' : 'warning'"
                /></template>
                <template #cell-actions="{ row }">
                    <span v-if="row.self" class="wd-data-secondary"
                        >You cannot change your own grant{{
                            row.last_owner ? '. You are the last usable platform owner' : ''
                        }}.</span
                    >
                    <ActionGroup v-else-if="!row.revoked_at" :label="`Actions for ${row.name}`">
                        <Button
                            type="button"
                            class="wd-button wd-button-secondary"
                            :ariaLabel="`Change capabilities for ${row.name}`"
                            @click="choose('change', { id: row.user_id, name: row.name, email: row.email }, row)"
                            >Change</Button
                        >
                        <Button
                            type="button"
                            class="wd-button wd-button-danger-outline"
                            :ariaLabel="`Revoke platform access for ${row.name}`"
                            @click="choose('revoke', { id: row.user_id, name: row.name, email: row.email }, row)"
                            >Revoke</Button
                        >
                    </ActionGroup>
                    <Button
                        v-else
                        type="button"
                        class="wd-button wd-button-secondary"
                        :ariaLabel="`Grant again to ${row.name}`"
                        @click="choose('grant', { id: row.user_id, name: row.name, email: row.email }, row)"
                        >Grant again</Button
                    >
                </template>
            </DataTable>
            <p class="wd-hint">
                To give access to someone new, open their account under Accounts and choose Grant platform access. The
                first platform owner, and recovery of a lost authenticator, are handled by the offline procedure.
            </p>
        </ContentSection>
        <ContentSection v-if="candidate && !candidate.verified" title="Grant platform access">
            <UnavailableNotice
                title="This account cannot receive platform access yet"
                :reason="`${candidate.name} (${candidate.email}) has not verified their email address. Only a verified identity can hold a grant.`"
            />
        </ContentSection>
        <div v-if="selected" ref="section">
            <ContentSection
                :title="
                    selected.mode === 'revoke'
                        ? 'Revoke platform access'
                        : selected.mode === 'change'
                          ? 'Change capabilities'
                          : 'Grant platform access'
                "
                :description="`For ${selected.name} (${selected.email}).`"
            >
                <form ref="root" @submit.prevent="submit">
                    <p v-if="form.errors.expected_version" class="wd-banner" role="alert">
                        {{ form.errors.expected_version }}
                        <Link href="/admin/operators" class="wd-link">Reload platform access</Link>
                    </p>
                    <p v-if="form.errors.user" class="wd-banner" role="alert">{{ form.errors.user }}</p>
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Platform access</legend>
                        <fieldset v-if="selected.mode !== 'revoke'" class="adm-capabilities">
                            <legend>Capabilities</legend>
                            <div v-for="key in capabilities" :key="key">
                                <label class="wd-checkbox"
                                    ><input
                                        v-model="form.capabilities"
                                        type="checkbox"
                                        :value="key"
                                        :aria-describedby="`capability-${key}`"
                                    />{{ capabilityLabels[key]?.label ?? key }}</label
                                >
                                <p :id="`capability-${key}`" class="wd-hint">{{ capabilityLabels[key]?.grants }}</p>
                            </div>
                            <p v-if="form.errors.capabilities" class="wd-field-error" role="alert">
                                {{ form.errors.capabilities }}
                            </p>
                        </fieldset>
                        <p v-if="removed.length" class="wd-warning" role="status">
                            This removes: {{ labels(removed) }}.
                        </p>
                        <FormField
                            id="operator-reason"
                            v-model="form.reason"
                            label="Reason"
                            :maxlength="240"
                            :error="form.errors.reason"
                            hint="Recorded in the administration audit."
                        />
                        <FormField
                            id="operator-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            label="Your password"
                            :error="form.errors.password"
                        />
                        <FormField
                            id="operator-code"
                            v-model="form.code"
                            label="Your authenticator code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            :maxlength="6"
                            :error="form.errors.code"
                            hint="A fresh six-digit code. Changing platform access always needs one."
                        />
                        <ActionGroup>
                            <Button
                                type="submit"
                                class="wd-button"
                                :class="selected.mode === 'revoke' ? 'wd-button-danger' : 'wd-button-primary'"
                                >{{ selected.mode === 'revoke' ? 'Revoke platform access' : 'Review and save' }}</Button
                            >
                            <Button type="button" class="wd-button wd-button-secondary" @click="selected = null"
                                >Cancel</Button
                            >
                        </ActionGroup>
                    </fieldset>
                </form>
            </ContentSection>
        </div>
    </AdminShell>
</template>
