<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\AgencyOverview;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency dashboard (admins): health of every active workspace and team workload.
 */
class AgencyController extends Controller
{
    public function __invoke(Request $request, AgencyOverview $overview): Response
    {
        return Inertia::render('reports/Agency', $overview->get($request->boolean('refresh')));
    }
}
