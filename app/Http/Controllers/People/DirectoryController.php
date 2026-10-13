<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff directory (B-18): who does what, who leads which team, who's on which client,
 * who's out today. Any signed-in member; client names only for clients the viewer can see.
 */
class DirectoryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $q = $request->validate(['search' => ['nullable', 'string', 'max:80'], 'department' => ['nullable', 'integer']]);

        $people = User::query()->active()->with(['primaryDepartment:id,name,color', 'roles:id,name'])
            ->when($q['search'] ?? null, fn (Builder $b, $s) => $b->where(fn (Builder $w) => $w
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower((string) $s).'%'])
                ->orWhereRaw('lower(coalesce(title, \'\')) like ?', ['%'.mb_strtolower((string) $s).'%'])))
            ->when($q['department'] ?? null, fn (Builder $b, $id) => $b->where('primary_department_id', (int) $id))
            ->orderBy('name')->limit(300)
            ->get(['id', 'name', 'title', 'primary_department_id']);
        $ids = $people->pluck('id')->all();

        $visible = Workspace::query()->visibleTo($viewer)->where('status', '!=', 'archived')->pluck('name', 'id');
        $clients = DB::table('workspace_user')->whereIn('user_id', $ids)->whereIn('workspace_id', $visible->keys())
            ->get(['user_id', 'workspace_id'])->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => (string) $visible[$r->workspace_id])->sort()->values()->all());
        $leads = Department::query()->whereNotNull('lead_user_id')->get(['name', 'lead_user_id'])->groupBy('lead_user_id')
            ->map(fn ($d) => $d->pluck('name')->all());
        $today = now()->toDateString();
        $out = LeaveRequest::query()->where('status', LeaveRequest::APPROVED)->whereIn('user_id', $ids)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->get(['user_id', 'ends_on'])
            ->mapWithKeys(fn (LeaveRequest $l) => [$l->user_id => $l->ends_on->toDateString()]);

        return Inertia::render('people/Directory', [
            'people' => $people->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'title' => $u->title,
                'department' => $u->primaryDepartment?->only(['id', 'name', 'color']),
                'leads' => $leads[$u->id] ?? [],
                'clients' => $clients[$u->id] ?? [],
                'out_until' => $out[$u->id] ?? null,
            ])->values(),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name']),
            'filters' => array_filter($q),
        ]);
    }
}
