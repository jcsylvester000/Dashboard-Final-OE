export type Option = { value: string; label: string };

export type DepartmentOption = { id: number; name: string; color: string };

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

export type UserRow = {
    id: number;
    name: string;
    email: string;
    title: string | null;
    is_active: boolean;
    role: string | null;
    department: DepartmentOption | null;
    two_factor: boolean;
    last_login_at: string | null;
};

export type MemberForm = {
    id?: number;
    name: string;
    email: string;
    title: string | null;
    role: string | null;
    primary_department_id: number | null;
    department_ids: number[];
};

export type Member = MemberForm & {
    id: number;
    is_active: boolean;
    must_change_password: boolean;
    two_factor: boolean;
    last_login_at: string | null;
    created_at: string | null;
    is_self: boolean;
};

export type AccessLinkRow = {
    id: number;
    purpose: 'setup' | 'reset';
    status: 'active' | 'used' | 'expired' | 'revoked';
    created_by: string | null;
    created_at: string;
    expires_at: string;
    used_at: string | null;
};

export type IssuedSecret = {
    type: 'link' | 'password';
    purpose: 'setup' | 'reset' | 'temporary';
    value: string;
    expiresAt: string | null;
};
