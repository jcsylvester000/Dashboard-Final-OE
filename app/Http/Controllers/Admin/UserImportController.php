<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\AccessLinkService;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E-93: add many people from a CSV (preview first, errors per row), and export the team list.
 *
 * Columns (header row required, any order): name, email, role, department, title,
 * access_ends_on. Role and department take the name or the key ("Finance" or "finance").
 * Each new person gets a one-time setup link, shown once to the admin (no email is sent).
 */
class UserImportController extends Controller
{
    public const MAX_ROWS = 200;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly AccessLinkService $links,
    ) {}

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);
        $request->validate([
            'file' => ['required', 'file', 'max:1024', 'mimes:csv,txt'],
            'preview' => ['boolean'],
        ]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $rows = $this->parse((string) file_get_contents($file->getRealPath()));
        $preview = $request->boolean('preview');
        $created = [];

        if (! $preview) {
            /** @var User $actor */
            $actor = $request->user();
            foreach ($rows as $i => $row) {
                if ($row['errors'] !== []) {
                    continue;
                }
                $user = DB::transaction(function () use ($row) {
                    $user = new User;
                    $user->fill(['name' => $row['name'], 'email' => $row['email'], 'title' => $row['title'], 'primary_department_id' => $row['department_id'], 'password' => bin2hex(random_bytes(32))]);
                    $user->forceFill(['email_verified_at' => now(), 'access_ends_on' => $row['access_ends_on']])->save();
                    $user->syncRoles([$row['role']]);
                    if ($row['department_id'] !== null) {
                        $user->departments()->sync([$row['department_id']]);
                    }

                    return $user;
                });
                ['url' => $url] = $this->links->issue($user, UserAccessLink::PURPOSE_SETUP, $actor);
                $rows[$i]['status'] = 'created';
                $created[] = ['name' => $user->name, 'email' => $user->email, 'link' => $url];
            }
            $this->activity->log('user.imported', null, ['created' => count($created), 'skipped' => count($rows) - count($created)]);
            // Setup links are secrets: keep them out of plain browser history.
            Inertia::encryptHistory();
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Nobody was added.|{1} 1 person added.|[2,*] :count people added.', count($created), ['count' => count($created)])]);
        }

        return back()->with('userImport', [
            'preview' => $preview,
            'rows' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['line', 'name', 'email', 'role', 'department', 'errors', 'status'])), $rows),
            'created' => $created,
        ]);
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('viewAny', User::class);
        $this->activity->log('user.exported');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['name', 'email', 'title', 'role', 'department', 'status', 'last_login', 'access_ends_on', 'two_factor']);
            User::query()->with(['roles:id,name', 'primaryDepartment:id,name'])->orderBy('name')->chunkById(500, function ($users) use ($out) {
                foreach ($users as $u) {
                    /** @var User $u */
                    fputcsv($out, [
                        $u->name, $u->email, $u->title ?? '', $u->getRoleNames()->first() ?? '', $u->primaryDepartment->name ?? '',
                        $u->is_active ? 'active' : 'deactivated', $u->last_login_at?->toDateString() ?? '',
                        $u->access_ends_on?->toDateString() ?? '', $u->two_factor_confirmed_at !== null ? 'yes' : 'no',
                    ]);
                }
            });
            fclose($out);
        }, 'team-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<array{line: int, name: string, email: string, role: string, department: string, title: string|null, department_id: int|null, access_ends_on: string|null, errors: list<string>, status: string}>
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; // Excel's byte-order mark
        $lines = array_values(array_filter(preg_split('/\R/', $csv) ?: [], fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return [];
        }
        $header = array_map(fn ($h) => Str::snake(trim(strtolower($h))), str_getcsv(array_shift($lines)));

        $roles = Role::query()->get()->mapWithKeys(fn (Role $r) => [strtolower($r->name) => $r->name, strtolower(RoleController::label($r)) => $r->name]);
        $departments = Department::query()->active()->get()->mapWithKeys(fn (Department $d) => [strtolower($d->name) => $d, strtolower($d->slug) => $d]);
        $existing = User::query()->pluck('email')->map(fn ($e) => strtolower((string) $e))->flip();
        $seen = [];
        $rows = [];

        foreach (array_slice($lines, 0, self::MAX_ROWS) as $n => $line) {
            $cells = str_getcsv($line);
            $get = fn (string $key) => trim((string) ($cells[array_search($key, $header, true)] ?? ''));
            $email = strtolower($get('email'));
            $roleKey = $roles[strtolower($get('role'))] ?? null;
            $deptRaw = $get('department');
            $dept = $deptRaw !== '' ? ($departments[strtolower($deptRaw)] ?? null) : null;
            $ends = $get('access_ends_on');
            $errors = [];

            if ($get('name') === '') {
                $errors[] = __('Name is missing.');
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = __('Email is not valid.');
            } elseif (isset($existing[$email])) {
                $errors[] = __('Someone already uses this email.');
            } elseif (isset($seen[$email])) {
                $errors[] = __('This email is twice in the file.');
            }
            if ($roleKey === null) {
                $errors[] = __('Unknown role ":r".', ['r' => $get('role')]);
            } elseif (Gate::denies('assignRole', [User::class, $roleKey])) {
                $errors[] = __('You cannot give the role ":r".', ['r' => $get('role')]);
            }
            if ($deptRaw !== '' && $dept === null) {
                $errors[] = __('Unknown department ":d".', ['d' => $deptRaw]);
            }
            if ($ends !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) !== 1 || $ends < now()->toDateString())) {
                $errors[] = __('Access end date must be today or later, like 2027-03-31.');
            }
            $seen[$email] = true;

            $rows[] = [
                'line' => $n + 2, 'name' => $get('name'), 'email' => $email, 'role' => (string) ($roleKey ?? $get('role')),
                'department' => $dept->name ?? $deptRaw, 'title' => $get('title') !== '' ? $get('title') : null,
                'department_id' => $dept?->id, 'access_ends_on' => $ends !== '' ? $ends : null,
                'errors' => $errors, 'status' => $errors === [] ? 'ready' : 'error',
            ];
        }

        return $rows;
    }
}
