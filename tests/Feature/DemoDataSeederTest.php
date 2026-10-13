<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo data is realistic English agency work for the three QA scenarios
 * and can be re-seeded without duplicates.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_three_scenarios_and_reseeds_cleanly()
    {
        Storage::fake('local');
        $this->seed(DemoDataSeeder::class);

        $this->assertEqualsCanonicalizing(['Brewhouse Coffee Co.', 'Bright Smile Dental', 'Desert Sun Realty'], Workspace::pluck('name')->all());
        $this->assertTrue(Project::where('name', 'Online Ordering App – MVP 1')->exists());
        $this->assertTrue(Project::where('name', 'Holiday 2026 Ads + Social Campaign')->exists());
        $this->assertTrue(Project::where('name', 'Arizona Market Launch Campaign')->exists());
        $this->assertSame('Marco Reyes', User::where('email', 'demo.development.1@overeasy.local')->value('name'));

        $tasks = Task::count();
        $entries = TimeEntry::count();
        $this->assertGreaterThan(50, $tasks);
        $this->assertGreaterThan(100, $entries);

        // Arizona handoff chain: Research -> Marketing -> Development.
        $integrate = Task::where('title', 'like', 'Integrate approved AZ assets%')->firstOrFail();
        $this->assertSame('AZ ad creatives and landing page copy', Task::find($integrate->handoff_from_task_id)?->title);

        // Sample files (3 approved Arizona deliverables) and @mentions that reach the Inbox.
        $this->assertSame(6, Attachment::count());
        $this->assertSame(3, Attachment::whereNotNull('approved_at')->count());
        $this->assertGreaterThan(0, DB::table('mentions')->count());

        // Nobody approves their own time.
        $this->assertSame(0, TimeEntry::whereColumn('approved_by', 'user_id')->count());

        $this->seed(DemoDataSeeder::class);
        $this->assertSame(3, Workspace::count());
        $this->assertSame($tasks, Task::count());
        $this->assertSame($entries, TimeEntry::count());
    }
}
