<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FinanceLayout from '../components/FinanceLayout.vue';
import ContentSection from '../components/ContentSection.vue';
import FieldGroup from '../components/FieldGroup.vue';
type Line = { description: string; quantity: number; unit_price_minor: string };
type Invoice = {
    id: string;
    recipient_name: string;
    currency: string;
    due_date: string | null;
    status: string;
    version: number;
    total_minor: string;
    lines: Line[];
};
const props = defineProps<{
    account: { name: string };
    workspace: { id: string; name: string };
    invoice?: Invoice;
    invoices?: { items: Invoice[]; nextCursor: string | null };
    errors?: Record<string, string>;
    status?: string;
}>();
const form = ref({
    recipient_name: props.invoice?.recipient_name ?? '',
    currency: props.invoice?.currency ?? '',
    due_date: props.invoice?.due_date ?? '',
    lines: props.invoice?.lines.map((l) => ({
        description: l.description,
        quantity: l.quantity,
        unit_price_minor: l.unit_price_minor,
    })) ?? [{ description: '', quantity: 1, unit_price_minor: '0' }],
});
const busy = ref(false);
const command = ref(crypto.randomUUID());
function submit(action: 'save' | 'void'): void {
    if (busy.value || props.errors?.version) return;
    router.visit(`/finance/invoices${props.invoice ? '/' + props.invoice.id : ''}${action === 'void' ? '/void' : ''}`, {
        method: props.invoice && action === 'save' ? 'patch' : 'post',
        data: { ...form.value, version: props.invoice?.version ?? 1, command_id: command.value },
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => {
            command.value = crypto.randomUUID();
        },
        preserveState: 'errors',
    });
}
</script>
<template>
    <Head title="Private invoice drafts" />
    <FinanceLayout
        :account-name="account.name"
        title="Private invoice drafts"
        active="invoices"
        :workspace-name="workspace.name"
        :status="status"
        :errors="errors"
    >
        <template #description
            >Draft — not issued. Prices and totals are integer minor units. No tax calculation or currency
            conversion.</template
        >
        <template #error-action
            ><Link v-if="invoice" class="wd-link" :href="`/finance/invoices/${invoice.id}`"
                >Reload draft</Link
            ></template
        >
        <ContentSection v-if="invoices" title="Saved drafts">
            <p v-if="!invoices.items.length" class="wd-empty">No saved drafts yet. Create your first draft below.</p>
            <ul>
                <li v-for="row in invoices.items" :key="row.id">
                    <Link class="wd-link" :href="`/finance/invoices/${row.id}`"
                        >{{ row.recipient_name }} — {{ row.currency }} {{ row.total_minor }} minor units ·
                        {{ row.status }}</Link
                    >
                </li>
            </ul>
            <Link class="wd-link" v-if="invoices.nextCursor" :href="`/finance/invoices?cursor=${invoices.nextCursor}`"
                >Next page</Link
            >
        </ContentSection>
        <ContentSection :title="invoice ? 'Draft details' : 'Create draft'">
            <p v-if="invoice">
                {{ invoice.status }} · Version {{ invoice.version }} · {{ invoice.currency }}
                {{ invoice.total_minor }} minor units
            </p>
            <form @submit.prevent="submit('save')">
                <fieldset class="wd-fields" :disabled="busy || invoice?.status === 'voided' || !!errors?.version">
                    <FieldGroup id="invoices-field-1" label="Recipient business name"
                        ><input id="invoices-field-1" v-model="form.recipient_name" required maxlength="160"
                    /></FieldGroup>
                    <FieldGroup id="invoices-field-2" label="Currency code"
                        ><input
                            id="invoices-field-2"
                            v-model="form.currency"
                            required
                            pattern="[A-Z]{3}"
                            maxlength="3"
                            placeholder="USD"
                    /></FieldGroup>
                    <FieldGroup id="invoices-field-3" label="Due date"
                        ><input id="invoices-field-3" v-model="form.due_date" type="date"
                    /></FieldGroup>
                    <fieldset class="wd-finance-line wd-fields" v-for="(line, index) in form.lines" :key="index">
                        <legend>Line {{ index + 1 }}</legend>
                        <FieldGroup :id="`invoices-field-4-${index}`" label="Description"
                            ><input
                                :id="`invoices-field-4-${index}`"
                                v-model="line.description"
                                required
                                maxlength="240"
                        /></FieldGroup>
                        <FieldGroup :id="`invoices-field-5-${index}`" label="Quantity"
                            ><input
                                :id="`invoices-field-5-${index}`"
                                v-model.number="line.quantity"
                                type="number"
                                min="1"
                                max="1000000"
                                step="1"
                                required
                        /></FieldGroup>
                        <FieldGroup :id="`invoices-field-6-${index}`" label="Unit price in minor units"
                            ><input
                                :id="`invoices-field-6-${index}`"
                                v-model="line.unit_price_minor"
                                inputmode="numeric"
                                pattern="0|[1-9][0-9]*"
                                required
                        /></FieldGroup>
                        <button
                            class="wd-button wd-button-secondary"
                            v-if="form.lines.length > 1"
                            type="button"
                            @click="form.lines.splice(index, 1)"
                        >
                            Remove line {{ index + 1 }}
                        </button>
                    </fieldset>
                    <button
                        class="wd-button wd-button-secondary"
                        v-if="form.lines.length < 50"
                        type="button"
                        @click="form.lines.push({ description: '', quantity: 1, unit_price_minor: '0' })"
                    >
                        Add line
                    </button>
                    <button class="wd-button wd-button-primary" type="submit">Save draft</button>
                </fieldset>
            </form>
        </ContentSection>
        <ContentSection
            v-if="invoice?.status === 'draft'"
            title="Void draft"
            description="Keep the draft history while marking it as voided."
        >
            <form
                v-if="invoice?.status === 'draft'"
                @submit.prevent="
                    confirmAction(
                        'Void draft',
                        `Void the draft for ${invoice.recipient_name}? Its history is preserved and it can no longer be edited.`,
                        () => submit('void'),
                        $event,
                    )
                "
            >
                <button class="wd-button wd-button-secondary" :disabled="busy || !!errors?.version">Void draft</button>
            </form>
        </ContentSection>
    </FinanceLayout>
</template>
