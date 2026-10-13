<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\RateCard;
use App\Models\RateTemplate;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 8e / brutal scenario H5: every rate change is recorded with who, when, from and to.
 */
class RateAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $aisha;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->finance = User::factory()->withRole('finance')->create(['name' => 'Grace']);
        $this->aisha = User::factory()->withRole('member')->create(['name' => 'Aisha']);
        $this->ws = Workspace::factory()->create(['name' => 'Brewhouse']);
        $this->ws->members()->attach($this->aisha->id, ['role' => 'member']);
    }

    public function test_agency_and_client_rate_changes_record_old_and_new_amounts()
    {
        $add = fn (array $body) => $this->actingAs($this->finance)->post(route('billing.rates.store'), $body)->assertSessionHasNoErrors();

        $add(['user_id' => $this->aisha->id, 'rate' => '2500', 'cost' => '900', 'effective_from' => '2026-01-01']);
        $add(['user_id' => $this->aisha->id, 'rate' => '3000', 'cost' => '1000', 'effective_from' => '2026-07-01']);
        $add(['workspace_id' => $this->ws->id, 'user_id' => $this->aisha->id, 'rate' => '3500', 'effective_from' => '2026-07-01']);

        $logs = ActivityLog::query()->where('action', 'rate.set')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        // The client rate's "from" is what applied before on that client: Aisha's agency rate from Jan.
        $this->assertSame([null, 250000, 250000], $logs->map(fn ($l) => $l->properties['from_minor'])->all());
        $this->assertSame('agency rate', $logs[2]->properties['from_note']);
        $this->assertSame([250000, 300000, 350000], $logs->map(fn ($l) => $l->properties['to_minor'])->all());
        $this->assertSame(['agency', 'agency', 'client'], $logs->map(fn ($l) => $l->properties['scope'])->all());
        $this->assertSame('Brewhouse', $logs[2]->properties['scope_label']);
        $this->assertTrue($logs->every(fn ($l) => $l->actor_id === $this->finance->id && $l->subject_id === $this->aisha->id));

        $card = RateCard::query()->where('rate_minor', 300000)->firstOrFail();
        $this->actingAs($this->finance)->delete(route('billing.rates.destroy', $card))->assertRedirect();
        $this->assertDatabaseHas('activity_log', ['action' => 'rate.removed', 'subject_id' => $this->aisha->id]);
    }

    public function test_template_edits_log_only_changed_rows_and_applying_logs_each_card()
    {
        $template = RateTemplate::create(['name' => 'Standard 2026']);
        $save = fn (string $rate) => $this->actingAs($this->finance)->put(route('billing.rate-templates.update', $template), [
            'name' => 'Standard 2026',
            'people' => [['id' => $this->aisha->id, 'rate' => $rate, 'cost' => '']],
            'departments' => [],
            'default' => ['rate' => '1500', 'cost' => ''],
        ])->assertSessionHasNoErrors();

        $save('3000');
        $this->assertSame(2, ActivityLog::query()->where('action', 'rate.template-changed')->count()); // Aisha + default added
        $save('3000');
        $this->assertSame(2, ActivityLog::query()->where('action', 'rate.template-changed')->count()); // nothing changed
        $save('3200');
        $last = ActivityLog::query()->where('action', 'rate.template-changed')->latest('id')->firstOrFail();
        $this->assertSame([300000, 320000, $this->aisha->id], [$last->properties['from_minor'], $last->properties['to_minor'], $last->subject_id]);

        $this->actingAs($this->finance)->post(route('billing.clients.rate-template', $this->ws), ['rate_template_id' => $template->id, 'effective_from' => '2026-10-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, ActivityLog::query()->where('action', 'rate.set')->where('properties->via', 'Standard 2026')->count());
    }

    public function test_history_page_filters_by_person_and_is_finance_only()
    {
        $this->actingAs($this->finance)->post(route('billing.rates.store'), ['user_id' => $this->aisha->id, 'rate' => '2500', 'effective_from' => '2026-01-01']);
        $this->actingAs($this->finance)->post(route('billing.rates.store'), ['rate' => '1500', 'effective_from' => '2026-01-01']);

        $this->actingAs($this->finance)->get(route('billing.rates.history', ['person' => $this->aisha->id]))
            ->assertInertia(fn ($page) => $page->component('billing/RateHistory')
                ->has('entries.data', 1)
                ->where('entries.data.0.for', 'Aisha')
                ->where('entries.data.0.actor', 'Grace')
                ->where('entries.data.0.from', null));
        $this->actingAs($this->finance)->get(route('billing.rates.history'))->assertInertia(fn ($page) => $page->has('entries.data', 2));

        $this->actingAs($this->aisha)->get(route('billing.rates.history'))->assertForbidden();
    }

    public function test_a_first_personal_rate_shows_the_department_rate_it_replaces()
    {
        $dept = (int) Department::where('slug', 'development')->value('id');
        $this->aisha->forceFill(['primary_department_id' => $dept])->save();
        RateCard::create(['department_id' => $dept, 'rate_minor' => 300000, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->finance)->post(route('billing.rates.store'), ['user_id' => $this->aisha->id, 'rate' => '3200', 'effective_from' => '2026-11-01'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::query()->where('action', 'rate.set')->latest('id')->firstOrFail();
        $this->assertSame([300000, 'department rate', 320000], [$log->properties['from_minor'], $log->properties['from_note'], $log->properties['to_minor']]);
    }
}
