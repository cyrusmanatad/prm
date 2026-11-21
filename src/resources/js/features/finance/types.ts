export type InvoiceLine = { description: string; quantity: number; unit_price_minor: string; total_minor?: string };
export type InvoiceAllocation = {
    id: string;
    amount_minor: string;
    evidence_reference: string;
    received_on: string;
    reversal_reason: string | null;
};
export type Invoice = {
    id: string;
    recipient_name: string;
    currency: string;
    due_date: string | null;
    status: 'draft' | 'voided' | string;
    total_minor: string;
    received_minor: string;
    version: number;
    line_count?: number | null;
    lines?: InvoiceLine[];
    allocations?: InvoiceAllocation[];
};
export type InvoiceTotals = {
    currency: string;
    drafts: number;
    total_minor: string;
    received_minor: string;
    balance_minor: string;
};
export type InvoiceSummary = {
    currencies: InvoiceTotals[];
    open: number;
    voided: number;
    pastDue: number;
};
export type Allocation = {
    id: string;
    invoice_id: string;
    amount_minor: string;
    reversal_reason: string | null;
    recipient_name: string | null;
};
export type Payment = {
    id: string;
    currency: string;
    amount_minor: string;
    received_on: string;
    evidence_reference: string;
    status: 'recorded' | 'voided' | string;
    version: number;
    correction_of: string | null;
    allocated_minor: string;
    allocations: Allocation[];
};
export type OpenDraft = {
    id: string;
    recipient_name: string;
    currency: string;
    version: number;
    due_date: string | null;
    total_minor: string;
    received_minor: string;
};
export type PaymentTotals = {
    currency: string;
    receipts: number;
    recorded_minor: string;
    allocated_minor: string;
    unallocated_minor: string;
};
export type PaymentSummary = {
    currencies: PaymentTotals[];
    recorded: number;
    voided: number;
    unallocated: number;
};
