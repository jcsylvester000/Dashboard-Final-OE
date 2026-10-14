export type InvoiceStatus =
    | 'draft'
    | 'finalized'
    | 'partially_paid'
    | 'paid'
    | 'void';

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
    days_overdue?: number;
    promise_to_pay_on?: string | null;
    last_contact_at?: string | null;
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
    manual: boolean;
    taxable: boolean;
    discount_bp: number | null;
    reason: string | null;
    sources: InvoiceSource[];
};

export type InvoiceDetail = InvoiceRow & {
    missing_rates?: {
        user_id: number | null;
        name: string;
        department_id: number | null;
        minutes: number;
        first: string;
        last: string;
    }[];
    subtotal_minor: number;
    tax_rate_bp: number;
    line_grouping: string;
    project?: { id: number; name: string } | null;
    tax_minor: number;
    paid_minor: number;
    credited_minor: number;
    bill_to: Record<string, string | null> | null;
    notes: string | null;
    credited_invoice_id: number | null;
    service_label: string | null;
    review: {
        required: boolean;
        requested_at: string | null;
        requested_by: string | null;
        mine: boolean;
        note: string | null;
        can_finalize: boolean;
    };
    client_credit_minor: number;
    share: {
        url: string;
        expires_at: string | null;
        views: number;
        first_viewed_at: string | null;
    } | null;
    collection: {
        days_overdue: number;
        stage: number;
        promise_to_pay_on: string | null;
        contacts: {
            id: number;
            note: string;
            promise_to_pay_on: string | null;
            by: string | null;
            at: string | null;
        }[];
    };
    items: InvoiceLine[];
    history: {
        id: number;
        from: string | null;
        to: string;
        note: string | null;
        by: string | null;
        at: string;
    }[];
    payments: {
        id: number;
        amount_minor: number;
        method: string;
        reference: string | null;
        received_on: string;
        by: string | null;
        note: string | null;
        is_credit: boolean;
        fee_minor: number;
        withholding_minor: number;
        received_minor: number | null;
        received_currency: string | null;
        credit_minor: number;
        reversed_at: string | null;
        reversed_by: string | null;
        reversal_reason: string | null;
    }[];
    credit_notes: InvoiceRow[];
};

export type RateRow = {
    id: number;
    workspace_id: number | null;
    department: string | null;
    person: string | null;
    rate_minor: number;
    cost_minor: number | null;
    currency?: string;
    effective_from: string;
};
