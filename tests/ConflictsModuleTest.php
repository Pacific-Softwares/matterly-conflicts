<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Modules\MatterlyConflicts\Models\Check;
use Modules\MatterlyConflicts\Models\Party;
use Modules\MatterlyConflicts\Support\ConflictSearch;
use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Tests\TestCase;

/**
 * Runs inside Matterly:  php artisan test ../licentra/modules/matterly-conflicts/tests/ConflictsModuleTest.php
 * The module is loaded in developer mode from a scratch folder (never your real modules-dev/).
 */
class ConflictsModuleTest extends TestCase
{
    private static string $root;

    private static array $env = [];

    public function createApplication()
    {
        self::$root = sys_get_temp_dir().'/matterly-conflicts-test-'.getmypid();
        @mkdir(self::$root.'/modules-dev', 0777, true);
        @symlink(dirname(__DIR__), self::$root.'/modules-dev/matterly-conflicts');
        self::$env = [
            'LICENTRA_MODULES_DEV' => 'true',
            'LICENTRA_MODULES_PATH' => self::$root.'/modules',
            'LICENTRA_MODULES_DEV_PATH' => self::$root.'/modules-dev',
            'LICENTRA_MODULES_PUBLIC_PATH' => self::$root.'/public-modules',
            'LICENTRA_MODULES_REGISTRY' => self::$root.'/licentra-modules.php',
        ];
        foreach (self::$env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('module:migrate', ['slug' => 'matterly-conflicts']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (array_keys(self::$env) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        putenv('LICENTRA_MODULES_DEV=false');
        $_ENV['LICENTRA_MODULES_DEV'] = $_SERVER['LICENTRA_MODULES_DEV'] = 'false';
        @unlink(self::$root.'/modules-dev/matterly-conflicts');
        (new Filesystem)->deleteDirectory(self::$root);
    }

    private function client(Firm $firm, string $first, string $last, array $extra = []): Client
    {
        return Client::withoutGlobalScope('firm')->create(['firm_id' => $firm->id, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first).'@example.com'] + $extra);
    }

    private function party(Firm $firm, string $name, string $role = 'adverse', ?Matter $matter = null): Party
    {
        return Party::withoutGlobalScope('firm')->create(['firm_id' => $firm->id, 'name' => $name, 'role' => $role, 'matter_id' => $matter?->id]);
    }

    public function test_the_module_loads_migrates_and_publishes_its_pages(): void
    {
        $this->assertTrue(app(ModuleLoader::class)->isLoaded('matterly-conflicts'));
        $this->assertFileExists(self::$root.'/public-modules/matterly-conflicts/build/pages.js');
        $this->assertFileExists(self::$root.'/public-modules/matterly-conflicts/build/pages.css');
    }

    public function test_names_are_matched_by_word_ignoring_company_suffixes(): void
    {
        $this->assertSame(['acme', 'holdings'], ConflictSearch::words('The ACME Holdings Ltd.'));
        $this->assertSame(['ltd'], ConflictSearch::words('Ltd'));
        $this->assertSame([], ConflictSearch::words(' a '));
    }

    public function test_a_search_finds_clients_their_matters_and_recorded_parties(): void
    {
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        $client = $this->client($firm, 'Maria', 'Okafor');
        $matter = Matter::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
        $this->party($firm, 'Okafor Shipping Ltd', 'adverse', $matter);

        $this->actingAs($admin)->get('/matterly-conflicts?q=okafor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('modules/matterly-conflicts/ConflictCheck', false)
                ->where('results.clients.0.name', 'Maria Okafor')
                ->where('results.parties.0.name', 'Okafor Shipping Ltd')
                ->where('results.parties.0.matter.number', $matter->matter_number)
                ->where('results.matters.0.id', $matter->id)
                ->where('results.adverse', 1)
                ->where('t.title', 'Conflict Check')
                ->where('moduleNav.firm.0.href', '/matterly-conflicts'));
    }

    public function test_a_search_never_shows_another_firms_records(): void
    {
        ['firm' => $other] = $this->createFirmSetup();
        $this->client($other, 'Victor', 'Hale');
        $this->party($other, 'Hale Industries');
        ['admin' => $admin] = $this->createFirmSetup();

        $this->actingAs($admin)->get('/matterly-conflicts?q=hale')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.count', 0));
    }

    public function test_recording_a_check_stores_what_the_server_found(): void
    {
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        $this->party($firm, 'Northwind Traders');

        $this->actingAs($admin)->post('/matterly-conflicts/checks', ['query' => 'Northwind', 'outcome' => 'conflict'])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin)->post('/matterly-conflicts/checks', [
            'query' => 'Northwind', 'outcome' => 'conflict', 'note' => 'We act against them in another matter.',
            'hits' => ['forged'], 'hit_count' => 99,
        ])->assertRedirect('/matterly-conflicts');

        $check = Check::withoutGlobalScope('firm')->sole();
        $this->assertSame($firm->id, $check->firm_id);
        $this->assertSame(1, $check->hit_count);
        $this->assertSame('Northwind Traders', $check->hits['parties'][0]['name']);
        $this->assertSame($admin->id, $check->resolved_by);
    }

    public function test_a_new_client_who_was_on_the_other_side_is_flagged_for_review(): void
    {
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        $this->party($firm, 'Daniel Brooks', 'adverse');
        $this->party($firm, 'Helen Ward', 'witness');

        $flagged = $this->client($firm, 'Daniel', 'Brooks');
        $this->client($firm, 'Helen', 'Ward');     // a witness isn't a conflict
        $this->client($firm, 'Someone', 'Else');

        $checks = Check::withoutGlobalScope('firm')->get();
        $this->assertCount(1, $checks);
        $this->assertSame('pending', $checks[0]->outcome);
        $this->assertSame('auto', $checks[0]->source);
        $this->assertSame($flagged->id, $checks[0]->client_id);

        $this->actingAs($admin)->get('/matterly-conflicts')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pending', 1));

        $this->actingAs($admin)->patch('/matterly-conflicts/checks/'.$checks[0]->id, ['outcome' => 'waived', 'note' => 'Both sides consented in writing.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('waived', $checks[0]->fresh()->outcome);
    }

    public function test_a_client_is_still_saved_when_the_module_has_no_tables(): void
    {
        ['firm' => $firm] = $this->createFirmSetup();
        \Illuminate\Support\Facades\Schema::drop('matterly_conflicts_checks');
        \Illuminate\Support\Facades\Schema::drop('matterly_conflicts_parties');

        $client = $this->client($firm, 'Daniel', 'Brooks');

        $this->assertTrue($client->exists);
    }

    public function test_parties_can_only_link_and_remove_the_firms_own_records(): void
    {
        ['firm' => $other] = $this->createFirmSetup();
        $theirMatter = Matter::factory()->create(['firm_id' => $other->id]);
        $theirParty = $this->party($other, 'Not yours');
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        $myMatter = Matter::factory()->create(['firm_id' => $firm->id]);

        $this->actingAs($admin)->post('/matterly-conflicts/parties', ['name' => 'Globex', 'role' => 'adverse', 'matter_id' => $theirMatter->id])
            ->assertSessionHasErrors('matter_id');
        $this->actingAs($admin)->post('/matterly-conflicts/parties', ['name' => 'Globex', 'role' => 'adverse', 'matter_id' => $myMatter->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete('/matterly-conflicts/parties/'.$theirParty->id);

        $this->assertTrue(Party::withoutGlobalScope('firm')->whereKey($theirParty->id)->exists());
        $this->assertSame($firm->id, Party::withoutGlobalScope('firm')->where('name', 'Globex')->value('firm_id'));

        $this->actingAs($admin)->get('/matterly-conflicts/parties')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('modules/matterly-conflicts/Parties', false)
                ->has('parties.data', 1)
                ->has('matters', 1));
    }

    public function test_staff_need_the_clients_permission(): void
    {
        ['firm' => $firm] = $this->createFirmSetup();

        $this->actingAs($this->createStaffUser($firm, 'clerk', ['matters']))->get('/matterly-conflicts')->assertForbidden();
        $this->actingAs($this->createStaffUser($firm, 'receptionist', ['clients']))->get('/matterly-conflicts')->assertOk();
    }

    public function test_the_csv_export_neutralises_formulas(): void
    {
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        Check::withoutGlobalScope('firm')->create(['firm_id' => $firm->id, 'query' => '=HYPERLINK("x")', 'outcome' => 'clear']);

        $csv = $this->actingAs($admin)->get('/matterly-conflicts/checks.csv')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_the_super_admin_sees_totals_only(): void
    {
        ['firm' => $firm, 'admin' => $admin] = $this->createFirmSetup();
        Check::withoutGlobalScope('firm')->create(['firm_id' => $firm->id, 'query' => 'Secret client', 'outcome' => 'conflict']);

        $this->actingAs($this->createSuperAdmin())->get('/matterly-conflicts/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('modules/matterly-conflicts/Overview', false)
                ->where('totals.checks', 1)
                ->where('totals.conflicts', 1)
                ->where('rows.0.firm', $firm->name))
            ->assertDontSee('Secret client');

        $this->actingAs($admin)->get('/matterly-conflicts/admin')->assertForbidden();
    }
}
