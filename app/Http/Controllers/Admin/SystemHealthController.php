<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\HealthChecks;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-96: queue, scheduler, failed jobs, disk, file drive, backups and version on one page.
 */
class SystemHealthController extends Controller
{
    public function __invoke(HealthChecks $checks): Response
    {
        return Inertia::render('admin/Health', [
            'checks' => $checks->run(),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }
}
