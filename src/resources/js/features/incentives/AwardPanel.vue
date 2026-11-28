<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, Clock, Info, Link2, Mail, TriangleAlert } from '@lucide/vue';
import DialogClose from '../../components/DialogClose.vue';
import FieldGroup from '../../components/FieldGroup.vue';
import FormField from '../../components/FormField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { dateOnly, dateTime } from '../admin/format';
import StageBadge from '../brand-leads/StageBadge.vue';
import { calendarDate } from '../campaigns/format';
import { today } from '../finance/amounts';
import { fromMinor, toMinor } from '../pipeline/money';
import { awardStates, cancelReasons, daysBetween, LATE_LINK_DAYS, rewardValue } from './format';
import type { AwardDetail } from './types';

/**
 * The award review side panel (mockup decision 3). A pending award is approved or rejected; an approved one is
 * marked fulfilled or reversed. Rejecting and reversing ask for confirmation. Closing returns focus to the list.
 */
const props = defineProps<{ award: AwardDetail; canManage: boolean; stageLabel: (stage: string) => string }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
onMounted(async () => {
    dialog.value?.showModal();
    await nextTick();
    dialog.value
        ?.querySelector<HTMLElement>(props.award.state === 'pending' ? 'input[name="decision"]' : '.wd-dialog-close')
        ?.focus();
});

const cash = computed(() => props.award.reward_type === 'cash' && !!props.award.currency);
const reward = computed(() => rewardValue(props.award));
const offer = computed(() => rewardValue({ ...props.award, amount_minor: props.award.offer_amount }));
const capLeft = computed(() =>
    props.award.cap_per_partner === null ? null : props.award.cap_per_partner - props.award.granted_partner,
);

const decision = useForm({
    version: props.award.version,
    choice: 'approve' as 'approve' | 'reject',
    amount: cash.value && props.award.amount_minor ? fromMinor(props.award.amount_minor, props.award.currency!) : '',
    reason: '',
});
const amountMinor = computed(() => (cash.value ? toMinor(decision.amount, props.award.currency!) : null));
const changedAmount = computed(() => cash.value && amountMinor.value !== props.award.offer_amount);
const reasonNeeded = computed(() => decision.choice === 'reject' || changedAmount.value);
function decide(event: Event): void {
    if (decision.processing) return;
    decision.clearErrors();
    if (cash.value && decision.choice === 'approve' && !amountMinor.value) {
        decision.setError('amount', 'Enter an amount greater than zero.');
        return;
    }
    if (reasonNeeded.value && !decision.reason.trim()) {
        decision.setError(
            'reason',
            decision.choice === 'reject'
                ? 'Give a reason. It is shown to the Partner.'
                : 'Say why the amount differs from the offer.',
        );
        void nextTick(() => document.getElementById('award-reason')?.focus());
        return;
    }
    const send = () =>
        decision
            .transform((data) => ({
                version: data.version,
                reason: data.reason.trim() || null,
                amount_minor: data.choice === 'approve' && cash.value ? amountMinor.value : null,
            }))
            .patch(`/brand/incentive-awards/${props.award.id}/${decision.choice}`, { preserveScroll: true });
    if (decision.choice === 'approve') return send();
    confirmAction(
        'Reject award',
        `Reject the award for ${props.award.partner_name ?? 'this Partner'} on “${props.award.incentive_title}”? The Partner sees your reason, and its leads can be used in another claim.`,
        send,
        event,
        { tone: 'danger' },
    );
}

const fulfil = useForm({ version: props.award.version, fulfilled_on: today(), reference: '' });
function markFulfilled(): void {
    if (!fulfil.processing) fulfil.patch(`/brand/incentive-awards/${props.award.id}/fulfil`, { preserveScroll: true });
}
const reverse = useForm({ version: props.award.version, reason: '' });
const reversing = ref(false);
function reverseApproval(event: Event): void {
    if (reverse.processing) return;
    if (!reverse.reason.trim()) {
        reverse.setError('reason', 'Give a reason. It is shown to the Partner.');
        return;
    }
    confirmAction(
        'Reverse approval',
        `Return the award for ${props.award.partner_name ?? 'this Partner'} to review? It no longer counts as owed, and the Partner sees your reason.`,
        () => reverse.patch(`/brand/incentive-awards/${props.award.id}/reverse`, { preserveScroll: true }),
        event,
        { tone: 'danger' },
    );
}
watch(
    () => props.award.version,
    (version) => {
        decision.defaults({ ...decision.data(), version, reason: '' });
        decision.reset();
        fulfil.version = version;
        reverse.version = version;
        reverse.reason = '';
        reversing.value = false;
        // The control that was used is gone after a decision; continue from the next step or the close button.
        void nextTick(() =>
            document.getElementById(props.award.state === 'approved' ? 'fulfil-date' : 'award-title')?.focus(),
        );
    },
);
async function startReverse(): Promise<void> {
    reversing.value = true;
    await nextTick();
    document.getElementById('reverse-reason')?.focus();
}
// Server errors for fields that are not form fields: the cap check and the amount as sent.
const serverErrors = computed(() => decision.errors as Record<string, string | undefined>);
const versionError = computed(
    () => decision.errors.version || fulfil.errors.version || reverse.errors.version || serverErrors.value.decision,
);

type HistoryItem = { label: string; at: string | null; date?: string };
const history = computed<HistoryItem[]>(() => {
    const award = props.award;
    const items: (HistoryItem | null)[] = [
        { label: `Claimed by ${award.partner_name ?? 'the Partner'}`, at: award.claimed_at },
        award.state === 'pending' && award.decision_reason
            ? { label: `Approval reversed: ${award.decision_reason}`, at: null }
            : null,
        award.decided_at ? { label: award.state === 'rejected' ? 'Rejected' : 'Approved', at: award.decided_at } : null,
        award.fulfilled_on
            ? {
                  label: `Marked fulfilled${award.fulfilment_reference ? ` · ${award.fulfilment_reference}` : ''}`,
                  at: null,
                  date: award.fulfilled_on,
              }
            : null,
        award.state === 'cancelled' && award.cancel_reason
            ? { label: `Cancelled: ${cancelReasons[award.cancel_reason]}`, at: null }
            : null,
    ];
    return items.filter((item): item is HistoryItem => item !== null);
});
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog wd-side-panel"
        aria-labelledby="award-title"
        @cancel.prevent="emit('close')"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="wd-side-panel-head">
            <div>
                <h2 id="award-title" tabindex="-1">Award for {{ award.partner_name ?? 'an ended relationship' }}</h2>
                <p class="wd-side-panel-meta">
                    {{ award.incentive_title }}
                    <StatusBadge :label="awardStates[award.state].label" :tone="awardStates[award.state].tone" />
                </p>
            </div>
            <DialogClose label="Close award" @click="emit('close')" />
        </header>
        <div class="wd-side-panel-body">
            <dl class="ic-facts">
                <div>
                    <dt>Reward</dt>
                    <dd>{{ reward }}</dd>
                </div>
                <div>
                    <dt>Claimed</dt>
                    <dd>
                        <time :datetime="award.claimed_at" :title="dateTime(award.claimed_at)">{{
                            dateOnly(award.claimed_at)
                        }}</time>
                    </dd>
                </div>
            </dl>
            <p v-if="award.note" class="cp-note">
                <Mail :size="14" aria-hidden="true" /><span
                    ><strong>Note from {{ award.partner_name ?? 'the Partner' }}:</strong> {{ award.note }}</span
                >
            </p>
            <p v-if="award.amount_reason" class="cp-note">
                <Info :size="14" aria-hidden="true" /><span
                    ><strong>Amount changed:</strong> {{ award.amount_reason }}</span
                >
            </p>

            <section aria-labelledby="cited-heading" class="ic-panel-section">
                <h3 id="cited-heading">Qualifying {{ award.leads.length === 1 ? 'lead' : 'leads' }}</h3>
                <ul class="ic-checklist">
                    <li v-for="lead in award.leads" :key="lead.id" class="ic-check is-static">
                        <span class="ic-check-body">
                            <span class="ic-check-top">
                                <span class="ic-check-name">{{ lead.name }}</span>
                                <StageBadge :stage="lead.stage" :label="stageLabel(lead.stage)" />
                            </span>
                            <span v-if="!lead.visible" class="ic-small ic-muted"
                                >No longer shared. Shown as it was when claimed.</span
                            >
                            <span v-else class="ic-small ic-muted">
                                Registered {{ lead.registered_at ? dateOnly(lead.registered_at) : '' }}
                                <template v-if="lead.campaign_title">
                                    · Source: {{ lead.campaign_title }}, linked
                                    {{ lead.linked_at ? dateOnly(lead.linked_at) : '' }}</template
                                >
                                <template v-else> · No source campaign</template>
                            </span>
                            <span
                                v-if="
                                    lead.registered_at &&
                                    lead.linked_at &&
                                    daysBetween(lead.registered_at, lead.linked_at) > LATE_LINK_DAYS
                                "
                                class="ic-flag"
                                ><Clock :size="12" aria-hidden="true" />Linked
                                {{ daysBetween(lead.registered_at, lead.linked_at) }} days after registering</span
                            >
                            <span
                                v-if="award.state === 'pending'"
                                class="ic-check-reason"
                                :class="{ 'is-ok': lead.meets }"
                            >
                                <component :is="lead.meets ? Check : TriangleAlert" :size="14" aria-hidden="true" />{{
                                    lead.meets
                                        ? `Meets the rule · ${stageLabel(lead.stage)}`
                                        : `Does not meet the rule now · needs ${stageLabel(award.rule_stage)} or later${award.campaign_title ? ` with ${award.campaign_title}` : ''}`
                                }}
                            </span>
                        </span>
                    </li>
                </ul>
            </section>

            <p v-if="versionError" class="wd-field-error" role="alert">{{ versionError }}</p>

            <form
                v-if="canManage && award.state === 'pending'"
                id="decision-form"
                class="ic-panel-form"
                novalidate
                @submit.prevent="decide"
            >
                <fieldset class="ic-segmented">
                    <legend class="wd-sr-only">Decision</legend>
                    <label>
                        <input v-model="decision.choice" type="radio" name="decision" value="approve" />
                        <span
                            ><span class="ic-choice-title">Approve</span
                            ><span class="ic-choice-sub">{{
                                capLeft === null
                                    ? 'No award limit per Partner'
                                    : `Uses 1 of ${capLeft} remaining for ${award.partner_name ?? 'this Partner'}`
                            }}</span></span
                        >
                    </label>
                    <label>
                        <input v-model="decision.choice" type="radio" name="decision" value="reject" />
                        <span
                            ><span class="ic-choice-title">Reject</span
                            ><span class="ic-choice-sub">The Partner sees your reason</span></span
                        >
                    </label>
                </fieldset>
                <FormField
                    v-if="cash && decision.choice === 'approve'"
                    id="award-amount"
                    v-model="decision.amount"
                    :label="`Amount (${award.currency})`"
                    inputmode="decimal"
                    :hint="`Offer value ${offer}. Changing it needs a reason.`"
                    :error="decision.errors.amount || serverErrors.amount_minor"
                />
                <FieldGroup
                    id="award-reason"
                    :label="reasonNeeded ? 'Reason' : 'Reason (optional)'"
                    :hint="decision.choice === 'reject' ? 'Shown to the Partner.' : undefined"
                    :error="decision.errors.reason"
                    v-slot="{ id, describedby, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="decision.reason"
                        rows="3"
                        maxlength="500"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid"
                    ></textarea>
                </FieldGroup>
                <p v-if="award.campaign_title && decision.choice === 'approve'" class="cp-note">
                    <Link2 :size="14" aria-hidden="true" /><span
                        >Approving also confirms <strong>{{ award.campaign_title }}</strong> as the source campaign of
                        {{ award.leads.length === 1 ? 'this lead' : 'these leads' }}.</span
                    >
                </p>
            </form>

            <form
                v-else-if="canManage && award.state === 'approved'"
                id="fulfil-form"
                class="ic-panel-form"
                novalidate
                @submit.prevent="markFulfilled"
            >
                <p class="cp-note">
                    <Check :size="14" aria-hidden="true" /><span
                        ><strong>Approved {{ award.decided_at ? dateOnly(award.decided_at) : '' }}.</strong> Deliver the
                        reward outside WeavDash, then record it here.</span
                    >
                </p>
                <div class="ic-pair">
                    <FormField
                        id="fulfil-date"
                        v-model="fulfil.fulfilled_on"
                        type="date"
                        label="Delivered on"
                        :error="fulfil.errors.fulfilled_on"
                    />
                    <FormField
                        id="fulfil-reference"
                        v-model="fulfil.reference"
                        label="Reference (optional)"
                        :required="false"
                        :maxlength="200"
                        hint="For example a transfer or courier reference. The Partner sees it."
                        :error="fulfil.errors.reference"
                    />
                </div>
                <p class="cp-note">
                    <Info :size="14" aria-hidden="true" /><span
                        >WeavDash records this for both workspaces. It does not send money.</span
                    >
                </p>
                <div v-if="reversing" class="ic-reverse">
                    <FieldGroup
                        id="reverse-reason"
                        label="Reason for reversing"
                        hint="Shown to the Partner. The award returns to review."
                        :error="reverse.errors.reason"
                        v-slot="{ id, describedby, invalid }"
                    >
                        <textarea
                            :id="id"
                            v-model="reverse.reason"
                            rows="2"
                            maxlength="500"
                            :aria-describedby="describedby"
                            :aria-invalid="invalid"
                        ></textarea>
                    </FieldGroup>
                </div>
            </form>

            <p v-else-if="award.state === 'rejected' && award.decision_reason" class="wd-warning">
                <strong>Reason:</strong> {{ award.decision_reason }}
            </p>

            <section aria-labelledby="history-heading" class="ic-panel-section">
                <h3 id="history-heading">History</h3>
                <ol class="ic-timeline">
                    <li v-for="item in history" :key="item.label">
                        <span
                            >{{ item.label }}
                            <time v-if="item.at" :datetime="item.at" :title="dateTime(item.at)">{{
                                dateOnly(item.at)
                            }}</time>
                            <time v-else-if="item.date" :datetime="item.date">{{ calendarDate(item.date) }}</time>
                        </span>
                    </li>
                </ol>
            </section>
        </div>
        <footer class="wd-side-panel-foot">
            <template v-if="canManage && award.state === 'pending'">
                <button type="button" class="wd-button wd-button-secondary" @click="emit('close')">Cancel</button>
                <Button
                    type="submit"
                    form="decision-form"
                    class="wd-button wd-button-primary"
                    :disabled="decision.processing"
                    >{{ decision.choice === 'approve' ? 'Approve award' : 'Reject award…' }}</Button
                >
            </template>
            <template v-else-if="canManage && award.state === 'approved'">
                <button v-if="!reversing" type="button" class="wd-link ic-danger-link" @click="startReverse">
                    Reverse approval…
                </button>
                <Button
                    v-else
                    type="button"
                    class="wd-button wd-button-secondary"
                    :disabled="reverse.processing"
                    @click="reverseApproval"
                    >Reverse approval…</Button
                >
                <Button
                    type="submit"
                    form="fulfil-form"
                    class="wd-button wd-button-primary"
                    :disabled="fulfil.processing"
                    >Mark fulfilled</Button
                >
            </template>
            <button v-else type="button" class="wd-button wd-button-secondary" @click="emit('close')">Close</button>
        </footer>
    </dialog>
</template>
