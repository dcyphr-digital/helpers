<?php

namespace DcyphrDigital\Helpers\Tests\Database;

use Carbon\Carbon;
use DcyphrDigital\Helpers\Services\Database\CreateOrUpdateService;
use DcyphrDigital\Helpers\Services\Database\Rules\RulesProvider;
use DcyphrDigital\Helpers\Tests\Fixtures\Person;
use DcyphrDigital\Helpers\Tests\Fixtures\PersonWithoutTimestamps;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CreateOrUpdateService against a real (in-memory SQLite) table: what is created, what is updated, what is left
 * alone, and whose updated_at changes.
 *
 * Records are stored at STORED_AT and the service runs at RUN_AT, so a record the service writes has
 * updated_at = RUN_AT and one it leaves alone keeps STORED_AT.
 */
class CreateOrUpdateServiceTest extends TestCase
{
    private const string STORED_AT = '2026-10-05 09:00:00';

    private const string RUN_AT = '2026-10-06 09:00:00';

    private const array MATCH_KEYS = ['brand_id', 'crm_id'];

    private int $updateStatements = 0;

    private int $insertStatements = 0;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('crm_id')->nullable();
            $table->unsignedInteger('website_id')->nullable();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('postcode')->nullable();
            $table->integer('points')->default(0);
            $table->boolean('is_active')->default(false);
            $table->date('date_of_birth')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->timestamps();
            // The match keys must be unique, or the service refuses them
            $table->unique(['brand_id', 'crm_id']);
            $table->unique(['brand_id', 'email']);
        });

        Schema::create('people_without_timestamps', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('crm_id');
            $table->string('email');
            $table->unique(['brand_id', 'crm_id']);
        });

        Carbon::setTestNow(self::STORED_AT);

        DB::listen(function ($query) {
            $sql = strtolower(ltrim($query->sql));
            $this->updateStatements += (int) str_starts_with($sql, 'update');
            $this->insertStatements += (int) str_starts_with($sql, 'insert');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function store(int $crmId, array $attributes = []): Person
    {
        return Person::create([
            'brand_id' => 1,
            'crm_id' => $crmId,
            'email' => "person{$crmId}@test.com",
            'first_name' => 'Anna',
            ...$attributes,
        ]);
    }

    private static function item(int $crmId, array $attributes = []): array
    {
        return [
            'brand_id' => 1,
            'crm_id' => $crmId,
            'email' => "person{$crmId}@test.com",
            'first_name' => 'Anna',
            ...$attributes,
        ];
    }

    /**
     * Runs the service at RUN_AT, counting only the statements it runs.
     */
    private function runService(array $items, array $reliableKeys = ['email', 'first_name'], array $options = [], string $model = Person::class): array
    {
        Carbon::setTestNow(self::RUN_AT);
        $this->updateStatements = 0;
        $this->insertStatements = 0;

        $service = new CreateOrUpdateService($items, $model);
        $written = $service->handle(...['reliableKeys' => $reliableKeys, 'matchKeys' => self::MATCH_KEYS, ...$options]);

        return [$service, $written];
    }

    private function updatedAt(Person $person): string
    {
        return $person->refresh()->updated_at->toDateTimeString();
    }

    // Creating

    public function test_creates_new_items_with_created_at_and_updated_at_of_now(): void
    {
        [$service, $written] = $this->runService([self::item(1), self::item(2, ['first_name' => 'Ben'])]);

        $this->assertTrue($written);
        $this->assertCount(2, $service->getToCreate());
        $this->assertSame([], $service->getToUpdate());
        $this->assertSame([], $service->getUnchanged());
        $this->assertSame(['Anna', 'Ben'], Person::orderBy('crm_id')->pluck('first_name')->all());
        $this->assertSame([self::RUN_AT, self::RUN_AT], Person::pluck('created_at')->map(fn ($date) => $date->toDateTimeString())->all());
        $this->assertSame([self::RUN_AT, self::RUN_AT], Person::pluck('updated_at')->map(fn ($date) => $date->toDateTimeString())->all());
    }

    public function test_creates_items_with_every_column_they_carry_not_only_the_reliable_keys(): void
    {
        $this->runService([self::item(1, ['last_name' => 'Smith', 'points' => 10])], reliableKeys: ['email']);

        $this->assertSame(['first_name' => 'Anna', 'last_name' => 'Smith', 'points' => 10], Person::first()->only(['first_name', 'last_name', 'points']));
    }

    public function test_creates_all_new_items_in_one_insert(): void
    {
        $this->runService([self::item(1), self::item(2), self::item(3)]);

        $this->assertSame(1, $this->insertStatements);
        $this->assertSame(3, Person::count());
    }

    public function test_creates_everything_without_match_keys(): void
    {
        $this->store(1);

        $service = new CreateOrUpdateService([self::item(2, ['email' => 'other@test.com'])], Person::class);
        $service->handle(reliableKeys: ['email'], matchKeys: []);

        $this->assertCount(1, $service->getToCreate());
        $this->assertSame([], $service->getExistingRecords());
        $this->assertSame(2, Person::count());
    }

    public function test_two_new_items_sharing_match_keys_fail_the_insert_and_write_nothing(): void
    {
        Log::spy();

        [$service, $written] = $this->runService([self::item(1, ['email' => 'first@test.com']), self::item(1, ['email' => 'second@test.com']), self::item(2)]);

        $this->assertFalse($written);
        $this->assertSame([], $service->getToCreate());
        $this->assertSame(0, Person::count());
    }

    public function test_validate_constraints_with_first_create_wins_keeps_the_first_of_two_new_items_sharing_match_keys(): void
    {
        Log::spy();

        [$service] = $this->runService(
            [self::item(1, ['email' => 'first@test.com']), self::item(1, ['email' => 'second@test.com']), self::item(2)],
            options: ['validateConstraints' => true, 'firstCreateWins' => true],
        );

        $this->assertSame(['first@test.com', 'person2@test.com'], Person::orderBy('crm_id')->pluck('email')->all());
        $this->assertSame('second@test.com', $service->getRejected()[0]['item']['email']);
    }

    public function test_refuses_match_keys_that_are_not_unique_and_writes_nothing(): void
    {
        Log::spy();
        $person = $this->store(1, ['email' => 'old@test.com']);

        $service = new CreateOrUpdateService([self::item(1, ['email' => 'new@test.com'])], Person::class);
        $written = $service->handle(reliableKeys: ['email'], matchKeys: ['first_name']);

        $this->assertFalse($written);
        $this->assertSame('old@test.com', $person->refresh()->email);
        $this->assertSame(1, Person::count());
    }

    // Updating

    public function test_updates_a_changed_record_and_its_updated_at_only(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);

        [$service, $written] = $this->runService([self::item(1, ['email' => 'new@test.com'])]);

        $this->assertTrue($written);
        $this->assertCount(1, $service->getToUpdate());
        $this->assertSame([], $service->getToCreate());
        $this->assertSame('new@test.com', $person->refresh()->email);
        $this->assertSame(self::RUN_AT, $person->updated_at->toDateTimeString());
        $this->assertSame(self::STORED_AT, $person->created_at->toDateTimeString());
        $this->assertSame(1, Person::count());
    }

    public function test_updates_only_the_reliable_keys(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com', 'last_name' => 'Stored', 'points' => 5]);

        $this->runService([self::item(1, ['email' => 'new@test.com', 'last_name' => 'Ignored', 'points' => 99])], reliableKeys: ['email']);

        $this->assertSame(['email' => 'new@test.com', 'last_name' => 'Stored', 'points' => 5], $person->refresh()->only(['email', 'last_name', 'points']));
    }

    public function test_never_updates_the_match_keys(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);

        // brand_id and crm_id are also named as reliable keys
        $this->runService([self::item(1, ['email' => 'new@test.com'])], reliableKeys: ['brand_id', 'crm_id', 'email']);

        $this->assertSame(['brand_id' => 1, 'crm_id' => 1, 'email' => 'new@test.com'], $person->refresh()->only(['brand_id', 'crm_id', 'email']));
    }

    public function test_updates_each_changed_record_with_its_own_values(): void
    {
        $anna = $this->store(1, ['first_name' => 'Anna']);
        $ben = $this->store(2, ['first_name' => 'Ben']);

        $this->runService([self::item(1, ['first_name' => 'Anne']), self::item(2, ['first_name' => 'Benjamin'])]);

        $this->assertSame('Anne', $anna->refresh()->first_name);
        $this->assertSame('Benjamin', $ben->refresh()->first_name);
    }

    public function test_writes_all_changed_records_of_a_batch_in_one_update_statement(): void
    {
        $this->store(1);
        $this->store(2);
        $this->store(3);

        $this->runService([self::item(1, ['first_name' => 'X']), self::item(2, ['first_name' => 'Y']), self::item(3, ['first_name' => 'Z'])]);

        $this->assertSame(1, $this->updateStatements);
        $this->assertSame(['X', 'Y', 'Z'], Person::orderBy('crm_id')->pluck('first_name')->all());
        $this->assertSame([self::RUN_AT], Person::pluck('updated_at')->map(fn ($date) => $date->toDateTimeString())->unique()->values()->all());
    }

    public function test_writes_more_than_500_changed_records_in_batches_of_500(): void
    {
        $now = now()->toDateTimeString();
        Person::insert(array_map(fn (int $i) => [
            'brand_id' => 1, 'crm_id' => $i, 'email' => "person{$i}@test.com", 'first_name' => 'Old', 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 501)));

        $this->runService(array_map(fn (int $i) => self::item($i, ['first_name' => "New {$i}"]), range(1, 501)));

        $this->assertSame(2, $this->updateStatements);
        $this->assertSame(0, Person::where('first_name', 'Old')->count());
        $this->assertSame('New 1', Person::where('crm_id', 1)->value('first_name'));
        $this->assertSame('New 501', Person::where('crm_id', 501)->value('first_name'));
        $this->assertSame(501, Person::where('updated_at', self::RUN_AT)->count());
    }

    public function test_writes_null_and_text_values_of_different_records_in_one_statement(): void
    {
        $this->store(1, ['last_name' => 'Smith']);
        $this->store(2, ['last_name' => null]);
        $this->store(3, ['last_name' => 'Jones']);

        $this->runService([self::item(1, ['last_name' => null]), self::item(2, ['last_name' => 'Brown']), self::item(3, ['last_name' => ''])], reliableKeys: ['last_name']);

        $this->assertSame(1, $this->updateStatements);
        $this->assertSame([1 => null, 2 => 'Brown', 3 => ''], Person::orderBy('crm_id')->pluck('last_name', 'crm_id')->all());
    }

    public function test_matches_a_null_match_key_to_a_stored_null(): void
    {
        $person = $this->store(1, ['crm_id' => null, 'email' => 'old@test.com']);

        [$service] = $this->runService([self::item(1, ['crm_id' => null, 'email' => 'new@test.com'])]);

        $this->assertCount(1, $service->getToUpdate());
        $this->assertSame('new@test.com', $person->refresh()->email);
        $this->assertSame(1, Person::count());
    }

    public function test_matches_on_every_match_key(): void
    {
        $otherBrand = $this->store(1, ['brand_id' => 2, 'email' => 'other@test.com']);

        [$service] = $this->runService([self::item(1)]);

        // Same crm_id in another brand: a new record, the other brand's is left alone
        $this->assertCount(1, $service->getToCreate());
        $this->assertSame(self::STORED_AT, $this->updatedAt($otherBrand));
        $this->assertSame(2, Person::count());
    }

    // Unchanged records and updated_at

    public function test_leaves_an_unchanged_record_and_its_updated_at_alone(): void
    {
        $person = $this->store(1);

        [$service, $written] = $this->runService([self::item(1)]);

        $this->assertTrue($written);
        $this->assertSame(0, $this->updateStatements);
        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertSame([], $service->getToUpdate());
        $this->assertSame([self::item(1)], $service->getUnchanged());
    }

    public function test_writes_only_the_changed_records_of_a_batch(): void
    {
        $anna = $this->store(1);
        $ben = $this->store(2, ['first_name' => 'Ben']);
        $cara = $this->store(3, ['email' => 'cara@old.com', 'first_name' => 'Cara']);

        [$service] = $this->runService([
            self::item(1),
            self::item(2, ['first_name' => 'Ben']),
            self::item(3, ['email' => 'cara@new.com', 'first_name' => 'Cara']),
            self::item(4, ['first_name' => 'Dan']),
        ]);

        $this->assertSame(1, $this->updateStatements);
        $this->assertSame(self::STORED_AT, $this->updatedAt($anna));
        $this->assertSame(self::STORED_AT, $this->updatedAt($ben));
        $this->assertSame(self::RUN_AT, $this->updatedAt($cara));
        $this->assertCount(1, $service->getToCreate());
        $this->assertCount(1, $service->getToUpdate());
        $this->assertCount(2, $service->getUnchanged());
    }

    public function test_leaves_a_record_alone_when_only_a_column_that_is_not_a_reliable_key_differs(): void
    {
        $person = $this->store(1, ['last_name' => 'Stored']);

        $this->runService([self::item(1, ['last_name' => 'Different'])]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertSame('Stored', $person->last_name);
    }

    public function test_leaves_a_record_alone_when_its_item_does_not_carry_a_reliable_key(): void
    {
        $person = $this->store(1, ['first_name' => 'Anna']);

        // first_name is a reliable key the item does not carry, so it is not written
        $this->runService([['brand_id' => 1, 'crm_id' => 1, 'email' => 'person1@test.com']]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertSame('Anna', $person->first_name);
    }

    public function test_leaves_records_alone_when_no_reliable_keys_are_given(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);

        [$service] = $this->runService([self::item(1, ['email' => 'new@test.com'])], reliableKeys: []);

        $this->assertSame(0, $this->updateStatements);
        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertSame('old@test.com', $person->email);
        // Nothing to compare, so it is not counted as unchanged either; the update just has nothing to write
        $this->assertCount(1, $service->getToUpdate());
    }

    #[DataProvider('unchangedValueProvider')]
    public function test_leaves_a_value_stored_the_same_way_alone(array $stored, array $new, array $reliableKeys): void
    {
        $person = $this->store(1, $stored);

        $this->runService([self::item(1, $new)], $reliableKeys);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
    }

    public static function unchangedValueProvider(): array
    {
        return [
            'same text' => [['last_name' => 'Smith'], ['last_name' => 'Smith'], ['last_name']],
            'both null' => [['last_name' => null], ['last_name' => null], ['last_name']],
            'integer column and numeric text' => [['points' => 150], ['points' => '150'], ['points']],
            'boolean column and true' => [['is_active' => true], ['is_active' => true], ['is_active']],
            'boolean column and false' => [['is_active' => false], ['is_active' => false], ['is_active']],
            'boolean column and 1' => [['is_active' => true], ['is_active' => 1], ['is_active']],
            'date column and the date at midnight' => [['date_of_birth' => '1990-01-01'], ['date_of_birth' => '1990-01-01 00:00:00'], ['date_of_birth']],
            'date column and the date' => [['date_of_birth' => '1990-01-01'], ['date_of_birth' => '1990-01-01'], ['date_of_birth']],
            'date column and Carbon' => [['date_of_birth' => '1990-01-01'], ['date_of_birth' => Carbon::parse('1990-01-01')], ['date_of_birth']],
            'datetime column and the same time' => [['registered_at' => '2026-01-01 08:30:00'], ['registered_at' => '2026-01-01 08:30:00'], ['registered_at']],
            'datetime column and Carbon' => [['registered_at' => '2026-01-01 08:30:00'], ['registered_at' => Carbon::parse('2026-01-01 08:30:00')], ['registered_at']],
        ];
    }

    #[DataProvider('changedValueProvider')]
    public function test_writes_a_value_that_would_change(array $stored, array $new, array $reliableKeys, string $column, mixed $expected): void
    {
        $person = $this->store(1, $stored);

        $this->runService([self::item(1, $new)], $reliableKeys);

        $this->assertSame(self::RUN_AT, $this->updatedAt($person));
        $this->assertSame($expected, $person->getRawOriginal($column));
    }

    public static function changedValueProvider(): array
    {
        return [
            'different text' => [['last_name' => 'Smith'], ['last_name' => 'Smyth'], ['last_name'], 'last_name', 'Smyth'],
            'letter case' => [['email' => 'john@test.com'], ['email' => 'John@test.com'], ['email'], 'email', 'John@test.com'],
            'trailing space' => [['last_name' => 'Smith'], ['last_name' => 'Smith '], ['last_name'], 'last_name', 'Smith '],
            'null to text' => [['last_name' => null], ['last_name' => 'Smith'], ['last_name'], 'last_name', 'Smith'],
            'text to null' => [['last_name' => 'Smith'], ['last_name' => null], ['last_name'], 'last_name', null],
            'null to empty text' => [['last_name' => null], ['last_name' => ''], ['last_name'], 'last_name', ''],
            'empty text to null' => [['last_name' => ''], ['last_name' => null], ['last_name'], 'last_name', null],
            'integer change' => [['points' => 150], ['points' => 151], ['points'], 'points', 151],
            'boolean change' => [['is_active' => true], ['is_active' => false], ['is_active'], 'is_active', 0],
            // A text column keeps the text as written
            'leading zero in a text column' => [['postcode' => '0800'], ['postcode' => 800], ['postcode'], 'postcode', '800'],
            'different date' => [['date_of_birth' => '1990-01-01'], ['date_of_birth' => '1990-01-02'], ['date_of_birth'], 'date_of_birth', '1990-01-02'],
            'different time' => [['registered_at' => '2026-01-01 08:30:00'], ['registered_at' => '2026-01-01 08:30:01'], ['registered_at'], 'registered_at', '2026-01-01 08:30:01'],
        ];
    }

    public function test_compares_the_raw_stored_values_not_the_cast_ones(): void
    {
        // The model casts date_of_birth to a date and is_active to a boolean, which would never equal the raw item values
        $person = $this->store(1, ['date_of_birth' => '1990-01-01', 'is_active' => true]);

        $this->runService([self::item(1, ['date_of_birth' => '1990-01-01 00:00:00', 'is_active' => 1])], ['date_of_birth', 'is_active']);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
    }

    public function test_a_record_written_again_later_is_left_alone_the_second_time(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);
        $this->runService([self::item(1, ['email' => 'new@test.com'])]);

        Carbon::setTestNow('2026-10-07 09:00:00');
        $service = new CreateOrUpdateService([self::item(1, ['email' => 'new@test.com'])], Person::class);
        $service->handle(reliableKeys: ['email', 'first_name'], matchKeys: self::MATCH_KEYS);

        $this->assertSame(self::RUN_AT, $this->updatedAt($person));
        $this->assertCount(1, $service->getUnchanged());
    }

    public function test_leaves_an_unchanged_record_alone_in_a_table_without_timestamps(): void
    {
        $person = PersonWithoutTimestamps::create(['brand_id' => 1, 'crm_id' => 1, 'email' => 'same@test.com']);
        $changed = PersonWithoutTimestamps::create(['brand_id' => 1, 'crm_id' => 2, 'email' => 'old@test.com']);

        [$service] = $this->runService(
            [['brand_id' => 1, 'crm_id' => 1, 'email' => 'same@test.com'], ['brand_id' => 1, 'crm_id' => 2, 'email' => 'new@test.com']],
            reliableKeys: ['email'],
            model: PersonWithoutTimestamps::class,
        );

        $this->assertSame(1, $this->updateStatements);
        $this->assertCount(1, $service->getUnchanged());
        $this->assertSame('same@test.com', $person->refresh()->email);
        $this->assertSame('new@test.com', $changed->refresh()->email);
    }

    // Default values for reliable keys

    public function test_writes_the_default_value_of_a_reliable_key_instead_of_the_item_value(): void
    {
        $person = $this->store(1, ['first_name' => 'Stored']);

        $this->runService([self::item(1, ['first_name' => 'Ignored'])], options: ['defaultValuesForReliableKeys' => ['first_name' => 'Default']]);

        $this->assertSame('Default', $person->refresh()->first_name);
        $this->assertSame(self::RUN_AT, $person->updated_at->toDateTimeString());
    }

    public function test_writes_the_default_value_on_every_record_of_a_batch(): void
    {
        $anna = $this->store(1, ['email' => 'old1@test.com', 'first_name' => 'Anna']);
        $ben = $this->store(2, ['email' => 'old2@test.com', 'first_name' => 'Ben']);

        $this->runService(
            [self::item(1, ['email' => 'new1@test.com', 'first_name' => 'Ignored']), self::item(2, ['email' => 'new2@test.com', 'first_name' => 'Ignored'])],
            options: ['defaultValuesForReliableKeys' => ['first_name' => 'Default']],
        );

        $this->assertSame(['email' => 'new1@test.com', 'first_name' => 'Default'], $anna->refresh()->only(['email', 'first_name']));
        $this->assertSame(['email' => 'new2@test.com', 'first_name' => 'Default'], $ben->refresh()->only(['email', 'first_name']));
    }

    public function test_leaves_a_record_alone_when_the_default_value_is_already_stored(): void
    {
        $person = $this->store(1, ['first_name' => 'Default']);

        [$service] = $this->runService([self::item(1, ['first_name' => 'Ignored'])], options: ['defaultValuesForReliableKeys' => ['first_name' => 'Default']]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertCount(1, $service->getUnchanged());
    }

    // onlyCreate and onlyUpdate

    public function test_only_create_creates_new_items_and_leaves_changed_records_alone(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);

        [$service] = $this->runService([self::item(1, ['email' => 'new@test.com']), self::item(2)], options: ['onlyCreate' => true]);

        $this->assertCount(1, $service->getToCreate());
        $this->assertSame([], $service->getToUpdate());
        $this->assertSame('old@test.com', $person->refresh()->email);
        $this->assertSame(self::STORED_AT, $person->updated_at->toDateTimeString());
        $this->assertSame(2, Person::count());
    }

    public function test_only_update_updates_changed_records_and_does_not_create_new_items(): void
    {
        $person = $this->store(1, ['email' => 'old@test.com']);

        [$service] = $this->runService([self::item(1, ['email' => 'new@test.com']), self::item(2)], options: ['onlyUpdate' => true]);

        $this->assertSame([], $service->getToCreate());
        $this->assertCount(1, $service->getToUpdate());
        $this->assertSame('new@test.com', $person->refresh()->email);
        $this->assertSame(1, Person::count());
    }

    public function test_only_update_still_leaves_unchanged_records_alone(): void
    {
        $person = $this->store(1);

        [$service] = $this->runService([self::item(1)], options: ['onlyUpdate' => true]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertCount(1, $service->getUnchanged());
    }

    // Rules

    public function test_if_null_then_update_fills_only_the_empty_columns_of_each_record(): void
    {
        $empty = $this->store(1, ['last_name' => null]);
        $filled = $this->store(2, ['last_name' => 'Kept']);

        $this->runService(
            [self::item(1, ['last_name' => 'Filled']), self::item(2, ['last_name' => 'Overwritten'])],
            reliableKeys: ['email'],
            options: ['rules' => [RulesProvider::IF_NULL_THEN_UPDATE]],
        );

        $this->assertSame('Filled', $empty->refresh()->last_name);
        $this->assertSame('Kept', $filled->refresh()->last_name);
    }

    public function test_if_null_then_update_decides_per_record_within_one_batch(): void
    {
        // In one statement, a column empty in one record must not be written to another that has it filled
        $empty = $this->store(1, ['last_name' => null, 'postcode' => '3000']);
        $filled = $this->store(2, ['last_name' => 'Kept', 'postcode' => null]);
        $both = $this->store(3, ['last_name' => null, 'postcode' => null]);

        $this->runService(
            [
                self::item(1, ['email' => 'new1@test.com', 'last_name' => 'Filled', 'postcode' => '9999']),
                self::item(2, ['email' => 'new2@test.com', 'last_name' => 'Overwritten', 'postcode' => '2000']),
                self::item(3, ['email' => 'new3@test.com', 'last_name' => 'Both', 'postcode' => '4000']),
            ],
            reliableKeys: ['email'],
            options: ['rules' => [RulesProvider::IF_NULL_THEN_UPDATE]],
        );

        $this->assertSame(1, $this->updateStatements);
        $this->assertSame(['email' => 'new1@test.com', 'last_name' => 'Filled', 'postcode' => '3000'], $empty->refresh()->only(['email', 'last_name', 'postcode']));
        $this->assertSame(['email' => 'new2@test.com', 'last_name' => 'Kept', 'postcode' => '2000'], $filled->refresh()->only(['email', 'last_name', 'postcode']));
        $this->assertSame(['email' => 'new3@test.com', 'last_name' => 'Both', 'postcode' => '4000'], $both->refresh()->only(['email', 'last_name', 'postcode']));
    }

    public function test_a_record_with_nothing_to_write_keeps_its_updated_at_when_others_in_the_batch_are_written(): void
    {
        $written = $this->store(1, ['email' => 'old@test.com']);
        $nothingToWrite = $this->store(2, ['last_name' => 'Kept']);

        // The second item differs only in last_name, which the rule keeps as it is not empty, and does not carry
        // the reliable key: it counts as changed, but the update has nothing to write to it
        $this->runService(
            [self::item(1, ['email' => 'new@test.com']), ['brand_id' => 1, 'crm_id' => 2, 'last_name' => 'Other']],
            reliableKeys: ['email'],
            options: ['rules' => [RulesProvider::IF_NULL_THEN_UPDATE]],
        );

        $this->assertSame(self::RUN_AT, $this->updatedAt($written));
        $this->assertSame(self::STORED_AT, $this->updatedAt($nothingToWrite));
        $this->assertSame('Kept', $nothingToWrite->last_name);
    }

    public function test_with_rules_leaves_a_record_alone_when_no_column_it_carries_differs(): void
    {
        $person = $this->store(1, ['last_name' => 'Kept']);

        [$service] = $this->runService([self::item(1, ['last_name' => 'Kept'])], reliableKeys: ['email'], options: ['rules' => [RulesProvider::IF_NULL_THEN_UPDATE]]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertCount(1, $service->getUnchanged());
    }

    public function test_with_rules_writes_a_record_when_any_column_it_carries_differs(): void
    {
        // The rule keeps last_name, as it is not empty, but the record still counts as changed and is written
        $person = $this->store(1, ['last_name' => 'Kept']);

        [$service] = $this->runService([self::item(1, ['last_name' => 'Other'])], reliableKeys: ['email'], options: ['rules' => [RulesProvider::IF_NULL_THEN_UPDATE]]);

        $this->assertCount(1, $service->getToUpdate());
        $this->assertSame('Kept', $person->refresh()->last_name);
        $this->assertSame(self::RUN_AT, $person->updated_at->toDateTimeString());
    }

    // Constraints and failures

    public function test_validate_constraints_skips_items_that_break_a_not_null_column_and_writes_the_rest(): void
    {
        Log::spy();

        [$service, $written] = $this->runService([self::item(1, ['email' => null]), self::item(2)], options: ['validateConstraints' => true]);

        $this->assertTrue($written);
        $this->assertCount(1, $service->getRejected());
        $this->assertSame(1, $service->getRejected()[0]['item']['crm_id']);
        $this->assertSame([2], Person::pluck('crm_id')->all());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_validate_constraints_with_first_create_wins_keeps_the_first_of_two_new_items_sharing_a_unique_value(): void
    {
        Log::spy();

        [$service] = $this->runService(
            [self::item(1, ['email' => 'shared@test.com']), self::item(2, ['email' => 'shared@test.com'])],
            options: ['validateConstraints' => true, 'firstCreateWins' => true],
        );

        $this->assertSame([1], Person::pluck('crm_id')->all());
        $this->assertSame(2, $service->getRejected()[0]['item']['crm_id']);
    }

    public function test_validate_constraints_still_leaves_unchanged_records_alone(): void
    {
        $person = $this->store(1);

        [$service] = $this->runService([self::item(1)], options: ['validateConstraints' => true]);

        $this->assertSame(self::STORED_AT, $this->updatedAt($person));
        $this->assertCount(1, $service->getUnchanged());
    }

    public function test_a_failed_insert_writes_nothing_returns_false_and_logs_the_error(): void
    {
        Log::spy();
        $this->store(1, ['email' => 'taken@test.com']);

        // Without validateConstraints, the unique email fails the whole insert
        [$service, $written] = $this->runService([self::item(2, ['email' => 'taken@test.com']), self::item(3)]);

        $this->assertFalse($written);
        $this->assertSame([], $service->getToCreate());
        $this->assertSame([], $service->getToUpdate());
        $this->assertSame(1, Person::count());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_failed_update_undoes_the_insert_so_the_created_records_are_not_lost(): void
    {
        Log::spy();
        $this->store(1);

        // The new item is inserted first; the update then fails on the email it just took
        [$service, $written] = $this->runService([self::item(2, ['email' => 'new@test.com']), self::item(1, ['email' => 'new@test.com'])]);

        $this->assertFalse($written);
        $this->assertSame([], $service->getToCreate());
        $this->assertSame(1, Person::count());
        $this->assertSame('person1@test.com', Person::first()->email);
    }

    public function test_an_item_without_a_match_key_fails_and_writes_nothing(): void
    {
        Log::spy();

        [$service, $written] = $this->runService([['brand_id' => 1, 'email' => 'no-crm-id@test.com']]);

        $this->assertFalse($written);
        $this->assertSame([], $service->getToCreate());
        $this->assertSame(0, Person::count());
    }

    public function test_no_items_writes_nothing_and_succeeds(): void
    {
        [$service, $written] = $this->runService([]);

        $this->assertTrue($written);
        $this->assertSame([], $service->getToCreate());
        $this->assertSame([], $service->getToUpdate());
        $this->assertSame([], $service->getUnchanged());
        $this->assertSame(0, $this->insertStatements + $this->updateStatements);
    }

    // getExistingRecords

    public function test_existing_records_hold_only_the_match_keys_without_rules_or_additional_columns(): void
    {
        $this->store(1);

        [$service] = $this->runService([self::item(1)]);

        $this->assertSame([['brand_id' => 1, 'crm_id' => 1]], $service->getExistingRecords());
    }

    public function test_existing_records_hold_the_additional_select_columns(): void
    {
        $this->store(1, ['last_name' => 'Stored']);

        [$service] = $this->runService([self::item(1)], options: ['additionalSelectColumns' => ['last_name']]);

        $this->assertSame([['brand_id' => 1, 'crm_id' => 1, 'last_name' => 'Stored']], $service->getExistingRecords());
    }

    public function test_the_getters_are_reset_on_every_handle(): void
    {
        $this->store(1);
        $service = new CreateOrUpdateService([self::item(1), self::item(2)], Person::class);

        $service->handle(reliableKeys: ['email'], matchKeys: self::MATCH_KEYS);
        $service->handle(reliableKeys: ['email'], matchKeys: self::MATCH_KEYS);

        // The second run finds both stored, unchanged
        $this->assertSame([], $service->getToCreate());
        $this->assertCount(2, $service->getUnchanged());
        $this->assertSame(2, Person::count());
    }
}
