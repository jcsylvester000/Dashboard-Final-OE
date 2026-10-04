export type User = {
    id: number;
    name: string;
    email: string;
    title?: string | null;
    avatar?: string;
    primary_department_id?: number | null;
    is_active?: boolean;
    must_change_password?: boolean;
    last_login_at?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Global permissions (mirror of App\Domain\Identity\Permissions). */
export type Permission =
    | 'users.view'
    | 'users.manage'
    | 'roles.manage'
    | 'departments.manage'
    | 'activity.view'
    | 'workspaces.view-all'
    | 'workspaces.manage'
    | 'reports.view-all'
    | 'billing.manage';

export type Auth = {
    user: User;
    roles: string[];
    /** Display-only. The server authorizes every request. */
    can: Partial<Record<Permission, boolean>>;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
