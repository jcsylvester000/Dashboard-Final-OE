<?php

namespace App\Domain\Identity;

/**
 * Single source of truth for global roles and permissions.
 * Seeded by RolesAndPermissionsSeeder; checked with Gate / `can:` middleware.
 * Workspace-level roles (Owner, Lead, Member, Guest) arrive in P2.
 */
final class Permissions
{
    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const DEPARTMENTS_MANAGE = 'departments.manage';

    public const ACTIVITY_VIEW = 'activity.view';

    public const WORKSPACES_VIEW_ALL = 'workspaces.view-all';

    public const WORKSPACES_MANAGE = 'workspaces.manage';

    public const REPORTS_VIEW_ALL = 'reports.view-all';

    public const BILLING_MANAGE = 'billing.manage';

    /**
     * @return array<string, string> permission => human label
     */
    public static function all(): array
    {
        return [
            self::USERS_VIEW => 'View team members',
            self::USERS_MANAGE => 'Create, edit and deactivate team members',
            self::ROLES_MANAGE => 'Edit role permissions',
            self::DEPARTMENTS_MANAGE => 'Manage departments',
            self::ACTIVITY_VIEW => 'View the audit log',
            self::WORKSPACES_VIEW_ALL => 'See every client workspace',
            self::WORKSPACES_MANAGE => 'Create and archive client workspaces',
            self::REPORTS_VIEW_ALL => 'View agency-wide reports',
            self::BILLING_MANAGE => 'Manage billing, rates and invoices',
        ];
    }

    /**
     * Default permission set per role. super-admin is handled by Gate::before.
     *
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        return [
            'super-admin' => ['label' => 'Super Admin', 'permissions' => []],
            'admin' => ['label' => 'Admin', 'permissions' => [
                self::USERS_VIEW, self::USERS_MANAGE, self::DEPARTMENTS_MANAGE, self::ACTIVITY_VIEW,
                self::WORKSPACES_VIEW_ALL, self::WORKSPACES_MANAGE, self::REPORTS_VIEW_ALL,
            ]],
            'finance' => ['label' => 'Finance', 'permissions' => [
                self::USERS_VIEW, self::WORKSPACES_VIEW_ALL, self::REPORTS_VIEW_ALL, self::BILLING_MANAGE,
            ]],
            'dept-lead' => ['label' => 'Department Lead', 'permissions' => [
                self::USERS_VIEW,
            ]],
            'member' => ['label' => 'Member', 'permissions' => []],
            'viewer' => ['label' => 'Viewer', 'permissions' => []],
        ];
    }

    /**
     * Roles only a super admin may grant or edit.
     *
     * @return list<string>
     */
    public static function protectedRoles(): array
    {
        return ['super-admin'];
    }
}
