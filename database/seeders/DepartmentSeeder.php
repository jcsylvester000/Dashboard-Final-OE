<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * The five agency departments that share one workflow.
     */
    public const DEFAULTS = [
        ['slug' => 'development', 'name' => 'Development', 'color' => 'blue', 'description' => 'Builds and maintains client sites and apps.'],
        ['slug' => 'research', 'name' => 'Research', 'color' => 'violet', 'description' => 'Market, competitor and audience research.'],
        ['slug' => 'product', 'name' => 'Product', 'color' => 'amber', 'description' => 'Scopes features, owns roadmaps and acceptance.'],
        ['slug' => 'marketing', 'name' => 'Marketing', 'color' => 'rose', 'description' => 'Campaigns, content, social and paid media.'],
        ['slug' => 'seo', 'name' => 'SEO', 'color' => 'emerald', 'description' => 'Technical, on-page, content and link work.'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $position => $department) {
            Department::firstOrCreate(
                ['slug' => $department['slug']],
                [...$department, 'position' => $position],
            );
        }
    }
}
