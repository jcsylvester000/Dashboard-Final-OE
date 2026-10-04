export type InvoiceStatus = 'draft' | 'finalized' | 'partially_paid' | 'paid' | 'void';

export type InvoiceRow = {
    id: number;
    number: string;
    kind: 'invoice' | 'credit_note';
    status: InvoiceStatus;
    workspace: { id: number; name: string; slug: string } | null;
    currency: string;
    period_start: string | null;
    period_end: string | null;
    issue_date: string | null;
    due_date: string | null;
    total_minor: number;
    balance_minor: number;
};

export type InvoiceSource = {
    id: number;
    type: string;
    minutes: number | null;
    amount_minor: number | null;
    date?: string;
    person?: string | null;
    task?: string | null;
    task_url?: string | null;
    note?: string | null;
};

export type InvoiceLine = {
    id: number;
    type: string;
    description: string;
    department: string | null;
    minutes: number | null;
    quantity: string;
    unit_minor: number;
    amount_minor: number;
    sources: InvoiceSource[];
};

export type InvoiceDetail = InvoiceRow & {
    subtotal_minor: number;
    tax_rate_bp: number;
    tax_minor: number;
    paid_minor: number;
    credited_minor: number;
    bill_to: Record<string, string | null> | null;
    notes: string | null;
    credited_invoice_id: number | null;
    items: InvoiceLine[];
    history: { id: number; from: string | null; to: string; note: string | null; by: string | null; at: string }[];
    payments: { id: number; amount_minor: number; method: string; reference: string | null; received_on: string; by: string | null }[];
    credit_notes: InvoiceRow[];
};

export type RateRow = {
    id: number;
    workspace_id: number | null;
    department: string | null;
    person: string | null;
    rate_minor: number;
    cost_minor: number | null;
    effective_from: string;
};
