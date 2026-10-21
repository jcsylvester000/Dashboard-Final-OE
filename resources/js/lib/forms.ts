/**
 * E-53: the same visibility and validation rules as App\Domain\Forms\FormSchema, so the
 * builder preview behaves exactly like the server: hidden fields are never required
 * and their answers are dropped.
 */
export type FieldType =
    | 'text'
    | 'textarea'
    | 'number'
    | 'date'
    | 'select'
    | 'multiselect'
    | 'file'
    | 'url'
    | 'yesno'
    | 'rating';

export type Condition = {
    field: string;
    op: 'eq' | 'neq' | 'filled' | 'empty' | 'includes';
    value: string | null;
} | null;

export type FormField = {
    key: string;
    label: string;
    type: FieldType;
    required: boolean;
    help: string | null;
    options: string[];
    show_if: Condition;
    max_files: number | null;
};

export type FormSection = {
    id: string;
    title: string | null;
    show_if: Condition;
    fields: FormField[];
};

export type FormSchemaShape = { sections: FormSection[] };

export type Answers = Record<
    string,
    string | number | string[] | null | undefined
>;

function filled(v: Answers[string]): boolean {
    return (
        v !== null &&
        v !== undefined &&
        v !== '' &&
        !(Array.isArray(v) && v.length === 0)
    );
}

export function passes(
    cond: Condition,
    answers: Answers,
    seen: Set<string>,
): boolean {
    if (!cond || !cond.field) {
        return true;
    }
    const v = seen.has(cond.field) ? answers[cond.field] : null;
    const t = String(cond.value ?? '');
    switch (cond.op) {
        case 'filled':
            return filled(v);
        case 'empty':
            return !filled(v);
        case 'neq':
            return (
                !filled(v) ||
                (Array.isArray(v) ? !v.includes(t) : String(v) !== t)
            );
        case 'includes':
            return Array.isArray(v) && v.map(String).includes(t);
        default:
            return (
                filled(v) &&
                (Array.isArray(v) ? v.map(String).includes(t) : String(v) === t)
            );
    }
}

export function visibleFields(
    schema: FormSchemaShape,
    answers: Answers,
): FormField[] {
    const seen = new Set<string>();
    const out: FormField[] = [];
    for (const s of schema.sections) {
        if (!passes(s.show_if, answers, seen)) {
            continue;
        }
        for (const f of s.fields) {
            if (passes(f.show_if, answers, seen)) {
                out.push(f);
                seen.add(f.key);
            }
        }
    }

    return out;
}

/** field key -> message, for the visible fields only. */
export function validate(
    schema: FormSchemaShape,
    answers: Answers,
    fileCounts: Record<string, number> = {},
): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const f of visibleFields(schema, answers)) {
        const v = answers[f.key];
        if (f.type === 'file') {
            const n = fileCounts[f.key] ?? 0;
            if (f.required && n === 0) {
                errors[f.key] = `${f.label} is required.`;
            } else if (n > (f.max_files ?? 1)) {
                errors[f.key] =
                    `Up to ${f.max_files ?? 1} file(s) for ${f.label}.`;
            }
            continue;
        }
        if (!filled(v)) {
            if (f.required) {
                errors[f.key] = `${f.label} is required.`;
            }
            continue;
        }
        const s = String(v);
        const bad =
            (f.type === 'text' && s.length > 500) ||
            (f.type === 'textarea' && s.length > 5000) ||
            (f.type === 'number' && Number.isNaN(Number(s))) ||
            (f.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(s)) ||
            (f.type === 'select' && !f.options.includes(s)) ||
            (f.type === 'multiselect' &&
                (!Array.isArray(v) || v.some((x) => !f.options.includes(x)))) ||
            (f.type === 'url' && !/^https?:\/\/\S+\.\S+/.test(s)) ||
            (f.type === 'yesno' && s !== 'yes' && s !== 'no') ||
            (f.type === 'rating' && !['1', '2', '3', '4', '5'].includes(s));
        if (bad) {
            errors[f.key] = `${f.label} is not valid.`;
        }
    }

    return errors;
}

export function emptyField(type: FieldType = 'text'): FormField {
    return {
        key: '',
        label: '',
        type,
        required: false,
        help: null,
        options:
            type === 'select' || type === 'multiselect' ? ['Option 1'] : [],
        show_if: null,
        max_files: type === 'file' ? 1 : null,
    };
}
