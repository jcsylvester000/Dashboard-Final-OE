<?php

namespace App\Domain\Identity;

/**
 * Single source of truth for global roles and permissions.
 * Seeded by RolesAndPermissionsSeeder; checked with Gate / `can:` middleware.
 * Workspace-level roles (Owner, Lead, Member, Guest) are set per workspace.
 *
 * E-89: billing and system are split into parts. The umbrella permissions
 * (billing.manage, system.manage) include every part (see IMPLIED; Gate::before
 * resolves it), so existing roles keep working and custom roles can take just a part.
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

    public const BILLING_VIEW = 'billing.view';

    public const BILLING_DRAFT = 'billing.draft';

    public const BILLING_FINALIZE = 'billing.finalize';

    public const BILLING_PAYMENTS = 'billing.payments';

    public const BILLING_SETTINGS = 'billing.settings';

    public const SYSTEM_MANAGE = 'system.manage';

    public const SYSTEM_JOBS = 'system.jobs';

    public const SYSTEM_HOLIDAYS = 'system.holidays';

    public const SYSTEM_FILES = 'system.files';

    public const SYSTEM_HEALTH = 'system.health';

    /** Umbrella permission => the parts it includes. */
    public const IMPLIED = [
        self::BILLING_MANAGE => [self::BILLING_VIEW, self::BILLING_DRAFT, self::BILLING_FINALIZE, self::BILLING_PAYMENTS, self::BILLING_SETTINGS],
        self::SYSTEM_MANAGE => [self::SYSTEM_JOBS, self::SYSTEM_HOLIDAYS, self::SYSTEM_FILES, self::SYSTEM_HEALTH],
    ];

    /** Every billing part needs billing.view to open the area. */
    public const REQUIRES = [
        self::BILLING_DRAFT => self::BILLING_VIEW,
        self::BILLING_FINALIZE => self::BILLING_VIEW,
        self::BILLING_PAYMENTS => self::BILLING_VIEW,
        self::BILLING_SETTINGS => self::BILLING_VIEW,
    ];

    /**
     * @return array<string, string> permission => human label
     */
    public static function all(): array
    {
        return [
            self::USERS_VIEW => 'View team members',
            self::USERS_MANAGE => 'Create, edit and deactivate team members',
            self::ROLES_MANAGE => 'Edit roles and their permissions',
            self::DEPARTMENTS_MANAGE => 'Manage departments',
            self::ACTIVITY_VIEW => 'View the audit log',
            self::WORKSPACES_VIEW_ALL => 'See every client workspace',
            self::WORKSPACES_MANAGE => 'Create and archive client workspaces',
            self::REPORTS_VIEW_ALL => 'View agency-wide reports',
            self::BILLING_MANAGE => 'Everything in billing',
            self::BILLING_VIEW => 'View invoices, rates and billing reports',
            self::BILLING_DRAFT => 'Prepare invoices',
            self::BILLING_FINALIZE => 'Finalize, void and credit invoices',
            self::BILLING_PAYMENTS => 'Record payments and follow up',
            self::BILLING_SETTINGS => 'Set rates and client billing',
            self::SYSTEM_MANAGE => 'Everything in system settings',
            self::SYSTEM_JOBS => 'Background jobs',
            self::SYSTEM_HOLIDAYS => 'Holidays',
            self::SYSTEM_FILES => 'File storage settings',
            self::SYSTEM_HEALTH => 'System health',
        ];
    }

    /**
     * Plain-language help shown in the permission matrix.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::USERS_VIEW => 'See the team list and profiles.',
            self::USERS_MANAGE => 'Invite people, change their role, reset access, deactivate.',
            self::ROLES_MANAGE => 'Create, copy and delete roles and change what each can do.',
            self::DEPARTMENTS_MANAGE => 'Add, rename and archive departments.',
            self::ACTIVITY_VIEW => 'Read and export the audit log of who did what.',
            self::WORKSPACES_VIEW_ALL => 'Open every client workspace, not just the ones they belong to.',
            self::WORKSPACES_MANAGE => 'Create client workspaces and archive them.',
            self::REPORTS_VIEW_ALL => 'Agency-wide time, workload and profitability reports.',
            self::BILLING_MANAGE => 'All billing parts below, now and any added later.',
            self::BILLING_VIEW => 'Open Billing: invoices, PDFs, statements, rates and reports. Needed for every billing part.',
            self::BILLING_DRAFT => 'Billing periods, building drafts, extra lines and discounts, expenses, fixed fees.',
            self::BILLING_FINALIZE => 'Give an invoice its number, send drafts back, void, issue credit notes, approve expenses.',
            self::BILLING_PAYMENTS => 'Record and reverse payments, log follow-ups, share invoice and statement links.',
            self::BILLING_SETTINGS => 'Rate cards and templates, client billing profiles (currency, tax, terms).',
            self::SYSTEM_MANAGE => 'All system parts below, now and any added later.',
            self::SYSTEM_JOBS => 'See and retry failed background jobs; performance dashboard.',
            self::SYSTEM_HOLIDAYS => 'Maintain the holiday calendar.',
            self::SYSTEM_FILES => 'File upload limits and storage settings.',
            self::SYSTEM_HEALTH => 'Queue, scheduler, storage and backup checks.',
        ];
    }

    /**
     * Matrix sections, in display order.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'People' => [self::USERS_VIEW, self::USERS_MANAGE, self::ROLES_MANAGE, self::DEPARTMENTS_MANAGE, self::ACTIVITY_VIEW],
            'Clients and reports' => [self::WORKSPACES_VIEW_ALL, self::WORKSPACES_MANAGE, self::REPORTS_VIEW_ALL],
            'Billing' => [self::BILLING_MANAGE, ...self::IMPLIED[self::BILLING_MANAGE]],
            'System' => [self::SYSTEM_MANAGE, ...self::IMPLIED[self::SYSTEM_MANAGE]],
        ];
    }

    /** The umbrella permission that includes this one, if any. */
    public static function parentOf(string $permission): ?string
    {
        foreach (self::IMPLIED as $parent => $children) {
            if (in_array($permission, $children, true)) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * A permission list plus everything its umbrella permissions include.
     *
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    public static function expand(iterable $permissions): array
    {
        $out = [];
        foreach ($permissions as $p) {
            $out[$p] = true;
            foreach (self::IMPLIED[$p] ?? [] as $child) {
                $out[$child] = true;
            }
        }

        return array_keys($out);
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
                self::WORKSPACES_VIEW_ALL, self::WORKSPACES_MANAGE, self::REPORTS_VIEW_ALL, self::SYSTEM_MANAGE,
            ]],
            'finance' => ['label' => 'Finance', 'permissions' => [
                self::USERS_VIEW, self::WORKSPACES_VIEW_ALL, self::REPORTS_VIEW_ALL, self::BILLING_MANAGE,
            ]],
            'bookkeeper' => ['label' => 'Bookkeeper', 'permissions' => [
                self::WORKSPACES_VIEW_ALL, self::BILLING_VIEW, self::BILLING_PAYMENTS,
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

    /**
     * Built-in roles: can be edited and copied, never deleted.
     *
     * @return list<string>
     */
    public static function builtInRoles(): array
    {
        return array_keys(self::roles());
    }
}
