<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import FieldGroup from '../components/FieldGroup.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
import { useValidationFocus } from '../composables/useValidationFocus';
import AdminCard from '../features/admin/AdminCard.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
import { dateOnly, dateTime } from '../features/admin/format';
import RequestHistory from '../features/brand-requests/RequestHistory.vue';
import SubmissionDetails from '../features/brand-requests/SubmissionDetails.vue';
import {
    brandSources,
    reviewStatuses,
    type BrandFields,
    type BrandHistoryEntry,
    type BrandStatus,
} from '../features/brand-requests/types';

type Decision = 'approve' | 'request_information' | 'decline';
const props = defineProps<{
    request: Partial<BrandFields> & {
        id: string;
        source: string;
        status: BrandStatus;
        version: number;
        submitted_at: string;
        updated_at: string;
        decided_at: string | null;
    };
    applicant: { id: number; name: string; email: string; verified: boolean; suspended: boolean; self: boolean } | null;
    workspace: { id: string; name: string; created_at: string; suspended: boolean } | null;
    history: BrandHistoryEntry[];
    canDirectory: boolean;
}>();
const base = computed(() => `/admin/brand-requests/${props.request.id}`);
const state = computed(() => reviewStatuses[props.request.status]);
const title = computed(() => props.request.brand_name ?? props.workspace?.name ?? 'Brand workspace');
const decidable = computed(() => props.request.source === 'request' && props.request.status === 'pending');
const eligible = computed(() => !!props.applicant?.verified && !props.applicant.suspended);
const root = ref<HTMLElement>();
const form = useForm({ expected_version: props.request.version, decision: '', feedback: '' });
// Loading the latest version keeps the feedback already typed and targets the version now shown.
watch(
    () => props.request.version,
    (version) => (form.expected_version = version),
);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
const actions: Record<Decision, string> = {
    approve: 'Approve request',
    request_information: 'Request more information',
    decline: 'Decline request',
};
const label = computed(() => actions[form.decision as Decision] ?? 'Record decision');
function decide(event: SubmitEvent) {
    if (form.processing) return;
    const send = () => form.post(`${base.value}/decision`, { preserveScroll: true });
    // Asking for information is routine and reversible by the applicant's reply, so it is not confirmed.
    if (form.decision !== 'approve' && form.decision !== 'decline') return send();
    const who = `${props.applicant?.name} (${props.applicant?.email})`;
    confirmAction(
        actions[form.decision],
        form.decision === 'approve'
            ? `Approve the request from ${who} for ${title.value}? A Brand workspace named ${title.value} is created now and ${props.applicant?.name} becomes its owner. You do not become a member. The applicant is emailed and sees your feedback. This cannot be undone here; suspend the workspace to pause its access later.`
            : `Decline the request from ${who} for ${title.value}? This request is closed permanently and no workspace is created. The applicant is emailed, sees your feedback, and may submit a new request.`,
        send,
        event,
        { tone: form.decision === 'decline' ? 'danger' : 'default' },
    );
}
</script>

<template>
    <AdminShell :title="title" compact>
        <template #back><BackLink href="/admin/brand-requests" label="All Brand requests" /></template>
        <template #description
            ><span class="adm-meta"
                ><span
                    >{{
                        request.source === 'rollout'
                            ? 'Approval recorded for a Brand workspace that existed before review was required'
                            : `Brand workspace request from ${applicant?.name ?? 'an applicant'}`
                    }}
                    · Submitted {{ dateOnly(request.submitted_at) }} · Reference</span
                ><CopyReference :value="request.id" label="request reference" /></span
        ></template>
        <template #actions>
            <StatusBadge :label="state?.label ?? request.status" :tone="state?.tone ?? 'neutral'" />
        </template>
        <AdminCard v-if="applicant" title="Applicant">
            <FactList
                :items="[
                    { key: 'name', label: 'Account' },
                    { key: 'email', label: 'Email', value: applicant.email },
                    { key: 'verified', label: 'Email verification' },
                    { key: 'access', label: 'Account access' },
                ]"
            >
                <template #name
                    ><Link v-if="canDirectory" :href="`/admin/accounts/${applicant.id}`" class="wd-link">{{
                        applicant.name
                    }}</Link
                    ><template v-else>{{ applicant.name }}</template></template
                >
                <template #verified
                    ><StatusBadge
                        :label="applicant.verified ? 'Verified' : 'Not verified'"
                        :tone="applicant.verified ? 'success' : 'warning'"
                /></template>
                <template #access
                    ><StatusBadge
                        :label="applicant.suspended ? 'Suspended' : 'Active'"
                        :tone="applicant.suspended ? 'danger' : 'success'"
                /></template>
            </FactList>
        </AdminCard>
        <AdminCard
            v-if="request.source === 'request'"
            title="Business information"
            note="Supplied by the applicant · not checked by WeavDash, and the address is not opened"
        >
            <div class="adm-panel-body"><SubmissionDetails :fields="request" link /></div>
        </AdminCard>
        <AdminCard title="Request">
            <FactList
                :items="[
                    { key: 'source', label: 'Source', value: brandSources[request.source] ?? request.source },
                    { key: 'submitted', label: 'Submitted', value: dateTime(request.submitted_at) },
                    { key: 'decided', label: 'Decided', value: dateTime(request.decided_at, 'Not decided') },
                    { key: 'workspace', label: 'Resulting workspace' },
                ]"
            >
                <template #workspace
                    ><template v-if="workspace"
                        ><Link v-if="canDirectory" :href="`/admin/workspaces/${workspace.id}`" class="wd-link">{{
                            workspace.name
                        }}</Link
                        ><template v-else>{{ workspace.name }}</template> · created
                        {{ dateTime(workspace.created_at) }}</template
                    ><template v-else>None: a workspace is created only on approval</template></template
                >
            </FactList>
        </AdminCard>
        <AdminCard v-if="decidable" title="Decision">
            <UnavailableNotice
                v-if="applicant?.self"
                title="You cannot review your own request"
                reason="Another operator with the Brand review capability must decide it."
            />
            <form v-else ref="root" class="adm-panel-body" :aria-busy="form.processing" @submit.prevent="decide">
                <p v-if="form.errors.expected_version" class="wd-banner rec-banner" role="alert">
                    {{ form.errors.expected_version }}
                    <Button type="button" class="wd-button wd-button-secondary" @click="router.reload()"
                        >Load the latest version</Button
                    >
                </p>
                <p v-if="!eligible" class="wd-warning" role="status">
                    Approval is unavailable:
                    {{
                        applicant?.suspended
                            ? "the applicant's account is suspended."
                            : "the applicant's email is not verified."
                    }}
                    You can still request more information or decline.
                </p>
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Decide this request</legend>
                    <SelectField
                        id="brand-decision"
                        v-model="form.decision"
                        label="Decision"
                        :options="[
                            { value: '', label: 'Choose a decision' },
                            ...(eligible ? [{ value: 'approve', label: 'Approve: create the Brand workspace' }] : []),
                            { value: 'request_information', label: 'Request more information' },
                            { value: 'decline', label: 'Decline: close this request' },
                        ]"
                        :error="form.errors.decision"
                    />
                    <FieldGroup
                        id="brand-feedback"
                        v-slot="{ id, describedby, invalid }"
                        label="Feedback for the applicant"
                        :error="form.errors.feedback"
                        hint="Required. The applicant reads this on their request page, so leave out internal notes. It is not included in email. Up to 1,000 characters."
                    >
                        <textarea
                            :id="id"
                            v-model="form.feedback"
                            rows="4"
                            maxlength="1000"
                            required
                            :aria-invalid="invalid"
                            :aria-describedby="describedby"
                        />
                    </FieldGroup>
                    <Button
                        type="submit"
                        class="wd-button"
                        :class="form.decision === 'decline' ? 'wd-button-danger' : 'wd-button-primary'"
                        >{{ form.processing ? 'Recording…' : label }}</Button
                    >
                </fieldset>
            </form>
        </AdminCard>
        <AdminCard v-else-if="request.status === 'needs_information'" title="Decision">
            <p class="rec-fine">
                Waiting for the applicant to update and resubmit. A decision can be recorded once the request is back in
                review.
            </p>
        </AdminCard>
        <AdminCard title="History" note="Submissions and decisions, newest first · nothing here is edited or removed">
            <div class="adm-panel-body"><RequestHistory :history="history" reviewer /></div>
        </AdminCard>
    </AdminShell>
</template>
