<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Seeder;

/**
 * Starter cross-department workflows. Idempotent: existing templates (by slug)
 * are left untouched so edits made in the app are kept.
 */
class WorkflowTemplateSeeder extends Seeder
{
    /**
     * slug => [name, description, steps[ [dept slug, title, offset days] ]]
     *
     * @var array<string, array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: int}>}>
     */
    public const TEMPLATES = [
        'client-onboarding' => ['Client Onboarding', 'Kick off a new client across every department.', [
            ['research', 'Market, competitor and audience research', 0],
            ['product', 'Define goals, scope and success metrics', 5],
            ['development', 'Technical audit and site / tracking setup', 10],
            ['seo', 'SEO baseline audit and keyword map', 14],
            ['marketing', 'Channel plan and first-month content calendar', 18],
        ]],
        'website-build' => ['Website Build', 'From scope to launch for a new or rebuilt site.', [
            ['product', 'Sitemap, wireframes and acceptance criteria', 0],
            ['development', 'Build pages and integrations', 7],
            ['seo', 'On-page SEO, schema and redirects', 21],
            ['development', 'QA, performance and accessibility pass', 25],
            ['marketing', 'Launch announcement and tracking check', 28],
        ]],
        'seo-audit-fix' => ['SEO Audit > Fix', 'Audit, hand fixes to Development, verify results.', [
            ['seo', 'Technical and content SEO audit', 0],
            ['development', 'Implement audit fixes', 5],
            ['seo', 'Verify fixes and request re-crawl', 12],
        ]],
        'campaign-launch' => ['Campaign Launch', 'Plan, build and launch a marketing campaign.', [
            ['research', 'Audience and offer research', 0],
            ['marketing', 'Campaign brief, copy and creatives', 4],
            ['development', 'Landing page and conversion tracking', 8],
            ['marketing', 'Launch campaign', 12],
            ['marketing', 'Two-week performance report', 26],
        ]],
        'monthly-report' => ['Monthly Client Report', 'Each department contributes to the monthly report.', [
            ['seo', 'Rankings and organic traffic summary', 0],
            ['marketing', 'Campaign and social performance summary', 0],
            ['research', 'Insights and opportunities', 2],
            ['product', 'Compile and review report with client lead', 4],
        ]],
    ];

    public function run(): void
    {
        $departments = Department::query()->pluck('id', 'slug');

        foreach (self::TEMPLATES as $slug => [$name, $description, $steps]) {
            if (WorkflowTemplate::where('slug', $slug)->exists()) {
                continue;
            }

            $template = WorkflowTemplate::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'is_active' => true,
            ]);

            foreach ($steps as $position => [$deptSlug, $title, $offset]) {
                $template->steps()->create([
                    'position' => $position,
                    'department_id' => $departments[$deptSlug] ?? null,
                    'title' => $title,
                    'offset_days' => $offset,
                    // Steps that start on the same day run in parallel.
                    'depends_on_previous' => $position > 0 && $offset > $steps[$position - 1][2],
                ]);
            }
        }
    }
}
