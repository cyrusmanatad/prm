<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FinanceLayout from '../components/FinanceLayout.vue';
import ContentSection from '../components/ContentSection.vue';
import FieldGroup from '../components/FieldGroup.vue';
import SelectField from '../components/SelectField.vue';
type Payment = {
    id: string;
    currency: string;
    amount_minor: string;
    received_on: string;
    evidence_reference: string;
    status: string;
    version: number;
    correction_of: string | null;
    allocated_minor: string;
    allocations: { id: string; invoice_id: string; amount_minor: string; reversal_reason: string | null }[];
};
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { name: string };
    drafts: {
        items: {
            id: string;
            recipient_name: string;
            currency: string;
            total_minor: string;
            version: number;
            status: string;
        }[];
        nextCursor: string | null;
    };
    payments: Payment[];
    nextCursor: string | null;
    errors?: Record<string, string>;
    status?: string;
}>();
const record = ref({ currency: '', amount_minor: '', received_on: '', evidence_reference: '', correction_of: '' });
const allocation = ref({ payment_id: '', invoice_id: '', version: 1, invoice_version: 1, amount_minor: '' });
const busy = ref(false);
const commands = new Map<string, string>();
function send(path: string, data: Record<string, string | number>): void {
    if (busy.value) return;
    const identity = path + JSON.stringify(data);
    const key = commands.get(identity) ?? crypto.randomUUID();
    commands.set(identity, key);
    router.post(
        path,
        { ...data, command_id: key },
        {
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            preserveState: 'errors',
        },
    );
}
const reason = ref('');
const correctionOptions = computed(() => [
    { value: '', label: 'No correction' },
    ...props.payments
        .filter((p) => p.status === 'voided')
        .map((p) => ({ value: p.id, label: `${p.evidence_reference} · ${p.currency} ${p.amount_minor}` })),
]);
const paymentOptions = computed(() => [
    { value: '', label: 'Choose payment' },
    ...props.payments
        .filter((p) => p.status === 'recorded')
        .map((p) => ({ value: p.id, label: `${p.evidence_reference} · ${p.currency}` })),
]);
const draftOptions = computed(() => [
    { value: '', label: 'Choose draft' },
    ...props.drafts.items
        .filter((d) => d.status === 'draft')
        .map((d) => ({
            value: d.id,
            label: `${d.recipient_name} · ${d.currency} ${d.total_minor} minor units`,
        })),
]);
</script>
<template>
    <Head title="Manual payment records" />
    <FinanceLayout
        :account-name="account.name"
        :account-email="account.email"
        title="Manual payment records"
        active="payments"
        :workspace-name="workspace.name"
        :status="status"
        :errors="errors"
    >
        <template #description
            >Record historical receipts only. No payment is initiated. Allocations against drafts are private
            reconciliation records.</template
        >
        <template #error-action><Link class="wd-link" href="/finance/payments">Reload records</Link></template>
        <div class="wd-finance-columns">
            <ContentSection title="Record receipt">
                <form @submit.prevent="send('/finance/payments', record)">
                    <fieldset class="wd-fields" :disabled="busy">
                        <FieldGroup id="payments-field-1" label="Currency code"
                            ><input
                                id="payments-field-1"
                                v-model="record.currency"
                                required
                                pattern="[A-Z]{3}"
                                maxlength="3"
                        /></FieldGroup>
                        <FieldGroup id="payments-field-2" label="Amount in minor units"
                            ><input
                                id="payments-field-2"
                                v-model="record.amount_minor"
                                required
                                inputmode="numeric"
                                pattern="[1-9][0-9]*"
                        /></FieldGroup>
                        <FieldGroup id="payments-field-3" label="Received on"
                            ><input id="payments-field-3" v-model="record.received_on" type="date" required
                        /></FieldGroup>
                        <FieldGroup id="payments-field-4" label="Unique evidence reference"
                            ><input id="payments-field-4" v-model="record.evidence_reference" required maxlength="160"
                        /></FieldGroup>
                        <SelectField
                            id="correction-receipt"
                            v-model="record.correction_of"
                            label="Corrects voided receipt (optional)"
                            :options="correctionOptions"
                        />
                        <button class="wd-button wd-button-primary" type="submit">Record manual receipt</button>
                    </fieldset>
                </form> </ContentSection
            ><ContentSection title="Allocate receipt">
                <form @submit.prevent="send(`/finance/payments/${allocation.payment_id}/allocate`, allocation)">
                    <fieldset class="wd-fields" :disabled="busy">
                        <SelectField
                            id="allocation-payment"
                            v-model="allocation.payment_id"
                            label="Payment"
                            :options="paymentOptions"
                            required
                            @change="
                                allocation.version = payments.find((p) => p.id === allocation.payment_id)?.version ?? 1
                            "
                        />
                        <SelectField
                            id="allocation-draft"
                            v-model="allocation.invoice_id"
                            label="Draft"
                            :options="draftOptions"
                            required
                            @change="
                                allocation.invoice_version =
                                    drafts.items.find((d) => d.id === allocation.invoice_id)?.version ?? 1
                            "
                        />
                        <Link
                            class="wd-link"
                            v-if="drafts.nextCursor"
                            :href="`/finance/payments?draft_cursor=${drafts.nextCursor}`"
                            >More drafts</Link
                        >
                        <FieldGroup id="payments-field-5" label="Allocation in minor units"
                            ><input
                                id="payments-field-5"
                                v-model="allocation.amount_minor"
                                required
                                pattern="[1-9][0-9]*"
                                inputmode="numeric"
                        /></FieldGroup>
                        <button class="wd-button wd-button-primary" type="submit">Allocate receipt</button>
                    </fieldset>
                </form>
            </ContentSection>
        </div>
        <ContentSection title="Receipt history">
            <p v-if="!payments.length" class="wd-empty">No receipts recorded yet.</p>
            <article class="wd-finance-record" v-for="p in payments" :key="p.id">
                <h3>{{ p.evidence_reference }}</h3>
                <p>
                    {{ p.status }} · {{ p.received_on }} · {{ p.currency }} {{ p.amount_minor }} minor units · Allocated
                    {{ p.allocated_minor }}
                </p>
                <p v-if="p.correction_of">Correction linked to the original voided receipt.</p>
                <ul>
                    <li v-for="a in p.allocations" :key="a.id">
                        <Link class="wd-link" :href="`/finance/invoices/${a.invoice_id}`">Open allocated draft</Link> ·
                        {{ a.amount_minor }} minor units
                        <span v-if="a.reversal_reason"> · Reversed: {{ a.reversal_reason }}</span>
                        <template v-else>
                            <FieldGroup :id="`reverse-reason-${a.id}`" label="Reason for reversing an allocation"
                                ><input :id="`reverse-reason-${a.id}`" v-model="reason" maxlength="240"
                            /></FieldGroup>
                            <button
                                type="button"
                                class="wd-button wd-button-danger"
                                :disabled="!reason.trim() || busy"
                                @click="
                                    confirmAction(
                                        'Reverse allocation',
                                        `Reverse ${a.amount_minor} minor units from receipt ${p.evidence_reference} allocated to draft ${a.invoice_id}? Reason: ${reason}. The reconciliation history is preserved.`,
                                        () => send(`/finance/allocations/${a.id}/reverse`, { reason }),
                                        $event,
                                        { tone: 'danger' },
                                    )
                                "
                            >
                                Reverse allocation
                            </button>
                        </template>
                    </li>
                </ul>
                <template v-if="p.status === 'recorded'"
                    ><button
                        type="button"
                        class="wd-button wd-button-danger"
                        :disabled="busy"
                        @click="
                            confirmAction(
                                'Void receipt',
                                `Void receipt ${p.evidence_reference}? It can no longer be allocated. Its history is preserved.`,
                                () => send(`/finance/payments/${p.id}/void`, { version: p.version }),
                                $event,
                                { tone: 'danger' },
                            )
                        "
                    >
                        Void receipt {{ p.evidence_reference }}
                    </button></template
                >
            </article>
            <Link class="wd-link" v-if="nextCursor" :href="`/finance/payments?cursor=${nextCursor}`"
                >Next receipts page</Link
            >
        </ContentSection>
    </FinanceLayout>
</template>
