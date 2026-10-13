<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\RateResolver;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\RateCard;
use App\Models\RateTemplate;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-person rates kept in templates and applied to a client as its own copy.
 */
class RateTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $ana;

    private User $ben;

    private User $outsider;

    private Workspace $ws;

    private int $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->dev = (int) Department::where('slug', 'development')->value('id');

        $this->finance = User::factory()->withRole('finance')->create();
        $this->ana = User::factory()->withRole('member')->create(['name' => 'Ana', 'primary_department_id' => $this->dev]);
        $this->ben = User::factory()->withRole('member')->create(['name' => 'Ben', 'primary_department_id' => $this->dev]);
        $this->outsider = User::factory()->withRole('member')->create(['name' => 'Olive']);

        $this->ws = Workspace::factory()->create();
        $this->ws->members()->attach([$this->ana->id => ['role' => 'member'], $this->ben->id => ['role' => 'member']]);

        RateCard::create(['workspace_id' => null, 'department_id' => null, 'user_id' => null, 'rate_minor' => 100000, 'effective_from' => '2026-01-01']);
    }

    private function template(): RateTemplate
    {
        $this->actingAs($this->finance)->post(route('billing.rate-templates.store'), ['name' => 'Standard 2026', 'copy_agency' => true])
            ->assertRedirect();
        $t = RateTemplate::where('name', 'Standard 2026')->firstOrFail();

        $this->actingAs($this->finance)->put(route('billing.rate-templates.update', $t), [
            'name' => 'Standard 2026',
            'people' => [
                ['id' => $this->ana->id, 'rate' => '3500', 'cost' => '1200'],
                ['id' => $this->ben->id, 'rate' => ''],
                ['id' => $this->outsider->id, 'rate' => '2000'],
            ],
            'departments' => [['id' => $this->dev, 'rate' => '2800', 'cost' => '']],
            'default' => ['rate' => '1500', 'cost' => ''],
        ])->assertSessionHasNoErrors();

        return $t;
    }

    public function test_templates_hold_per_person_rates_with_fallbacks()
    {
        $t = $this->template();

        // Ana, Olive, Development and default; Ben left blank.
        $this->assertSame(4, $t->items()->count());
        $this->actingAs($this->finance)->get(route('billing.rate-templates.edit', $t))
            ->assertInertia(fn (Assert $page) => $page->component('billing/RateTemplate')
                ->where('default.rate', '1500.00')
                ->where('people', fn ($people) => collect($people)->firstWhere('id', $this->ana->id)['rate'] === '3500.00'));
    }

    public function test_applying_a_template_gives_the_client_its_own_rates()
    {
        $this->travelTo(now()->setDate(2026, 10, 15)); // "today" for the client page's current rates
        $t = $this->template();

        $this->actingAs($this->finance)->post(route('billing.clients.rate-template', $this->ws), [
            'rate_template_id' => $t->id,
            'effective_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        // Olive isn't on this client: skipped. Ana, Development and default copied.
        $this->assertSame(3, RateCard::where('workspace_id', $this->ws->id)->count());
        $this->assertSame($t->id, BillingProfile::where('workspace_id', $this->ws->id)->value('rate_template_id'));

        $resolver = new RateResolver;
        $this->assertSame(350000, $resolver->resolve($this->ws->id, $this->dev, $this->ana->id, '2026-10-15')?->rate_minor);
        $this->assertSame(280000, $resolver->resolve($this->ws->id, $this->dev, $this->ben->id, '2026-10-15')?->rate_minor); // department fallback
        $this->assertSame(100000, $resolver->resolve($this->ws->id, $this->dev, $this->ana->id, '2026-09-30')?->rate_minor); // before: agency default

        // Changing the template later doesn't touch the client's copy.
        $this->actingAs($this->finance)->put(route('billing.rate-templates.update', $t), [
            'name' => 'Standard 2026', 'people' => [['id' => $this->ana->id, 'rate' => '9999']], 'departments' => [], 'default' => ['rate' => ''],
        ]);
        $this->assertSame(350000, (new RateResolver)->resolve($this->ws->id, $this->dev, $this->ana->id, '2026-10-15')?->rate_minor);

        // The client page shows each person's rate and where it comes from.
        $this->actingAs($this->finance)->get(route('billing.clients.show', $this->ws))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appliedTemplate.name', 'Standard 2026')
                ->where('teamRates', fn ($rows) => collect($rows)->firstWhere('id', $this->ana->id)['source'] === 'client-person'
                    && collect($rows)->firstWhere('id', $this->ben->id)['source'] === 'client-department'));
    }

    public function test_only_finance_manages_rate_templates()
    {
        $this->actingAs($this->ana)->post(route('billing.rate-templates.store'), ['name' => 'Mine'])->assertForbidden();
        $this->assertSame(0, RateTemplate::count());
    }
}
