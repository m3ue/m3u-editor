<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Events\ImportChunkProcessed;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Writer;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
    $this->withoutVite();
    $this->user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'url' => 'https://feed.invalid/synthetic.m3u',
        'enable_proxy' => false,
        'include_vod_in_m3u' => true,
        'auto_channel_increment' => false,
        'status' => 'completed',
    ]);
    $this->otherPlaylist = Playlist::factory()->for($this->user)->create();
});

function createCsvChannel(User $user, Playlist $playlist, bool $isVod, string $name, int $number): Channel
{
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'CSV group',
        'name_internal' => 'CSV group',
        'type' => $isVod ? 'vod' : 'live',
    ]);

    return Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'name' => $name,
        'title' => $name,
        'group' => 'CSV group',
        'is_vod' => $isVod,
        'channel' => $number,
        'channel_minor' => null,
        'enabled' => true,
        'url' => 'https://media.invalid/'.$number.'.ts',
        'enable_proxy' => false,
        'logo' => null,
        'logo_internal' => null,
        'shift' => 0,
        'stream_id' => 'csv-'.$number,
        'source_id' => 'csv-'.$number,
    ]);
}

function useCsvDatabaseQueue(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
        'queue.batching.database' => config('database.default'),
    ]);
    Bus::swap(Bus::getFacadeRoot()->dispatcher);
}

/**
 * @param  array{is_vod: bool, playlist: int}|null  $expectedImportOptions
 * @return array<class-string>
 */
function drainCsvJobs(?array $expectedImportOptions = null): array
{
    $jobs = [];
    $chunks = [];
    Event::listen(ImportChunkProcessed::class, function (ImportChunkProcessed $event) use (&$chunks): void {
        $chunks[] = $event;
    });
    $queue = app('queue')->connection('database');

    while ($job = $queue->pop()) {
        $name = $job->resolveName();
        expect(str_starts_with($name, 'Filament\\Actions\\') || in_array($name, [
            ChainedBatch::class,
            DatabaseNotification::class,
            DatabaseNotificationsSent::class,
            SendQueuedNotifications::class,
            BroadcastEvent::class,
        ], true))->toBeTrue($name);

        $jobs[] = $name;
        expect(count($jobs))->toBeLessThan(20);
        $job->fire();
        if (! $job->isDeleted()) {
            $job->delete();
        }
    }

    if ($expectedImportOptions !== null) {
        expect($chunks)->toHaveCount(1)
            ->and($chunks[0]->getOptions())->toMatchArray($expectedImportOptions)
            ->and($chunks[0]->getColumnMap())->toBe(['name' => 'Name', 'group' => 'Group', 'channel' => 'Channel', 'enabled' => 'Enabled']);
    }

    return $jobs;
}

/** @param array<array<string, string>> $rows */
function writeChannelCsv(array $rows): string
{
    $writer = Writer::fromString();
    $writer->insertOne(['Name', 'Group', 'Channel', 'Enabled']);
    $writer->insertAll(array_map(array_values(...), $rows));

    return $writer->toString();
}

it('roundtrips only the selected content type when live and VOD identities collide', function (bool $isVod) {
    // The older opposite-type row demonstrates eligibility, not a portable SQL ordering guarantee.
    $opposite = createCsvChannel($this->user, $this->playlist, ! $isVod, 'Same CSV identity', 17);
    $target = createCsvChannel($this->user, $this->playlist, $isVod, 'Same CSV identity', 29);
    $control = createCsvChannel($this->user, $this->otherPlaylist, $isVod, 'Same CSV identity', 53);
    $oppositeBefore = $opposite->fresh()->getRawOriginal();
    $controlBefore = $control->fresh()->getRawOriginal();
    $page = $isVod ? ListVod::class : ListChannels::class;
    useCsvDatabaseQueue();

    Livewire::test($page)
        ->loadTable()
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$opposite])
        ->mountAction('export')
        ->fillForm(['playlist' => $this->playlist->id])
        ->callMountedAction()
        ->assertHasNoFormErrors();
    expect(drainCsvJobs())->toContain(PrepareCsvExport::class, ExportCsv::class);
    $export = Export::latest('id')->firstOrFail();
    expect($export->successful_rows)->toBe(1);
    $this->actingAs($this->user);
    $bytes = $this->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
        ->assertOk()->streamedContent();
    $reader = Reader::fromString($bytes);
    $reader->setHeaderOffset(0);
    $rows = iterator_to_array($reader->getRecords(), false);
    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toBe(['Name', 'Group', 'Channel', 'Enabled'])
        ->and($rows[0]['Channel'])->toBe('29');

    $input = writeChannelCsv([array_replace($rows[0], ['Channel' => '63'])]);
    Livewire::test($page)->mountAction('import')->fillForm([
        'file' => UploadedFile::fake()->createWithContent('roundtrip.csv', $input),
        'playlist' => $this->playlist->id,
        'columnMap' => ['name' => 'Name', 'group' => 'Group', 'channel' => 'Channel', 'enabled' => 'Enabled'],
    ])->callMountedAction()->assertHasNoFormErrors();

    DB::enableQueryLog();
    expect(drainCsvJobs(['is_vod' => $isVod, 'playlist' => $this->playlist->id]))->toContain(ImportCsv::class);
    $lookups = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "channels" where ("name" =')
    )->values();
    DB::disableQueryLog();
    expect($lookups)->toHaveCount(1)
        ->and($lookups[0]['query'])->toContain('"is_vod" = ?')
        ->and(array_slice($lookups[0]['bindings'], 0, 4))->toBe(['Same CSV identity', 'CSV group', $this->playlist->id, $isVod]);
    $import = Import::latest('id')->firstOrFail();
    expect($import->successful_rows)->toBe(1)->and($import->completed_at)->not->toBeNull();
    $this->actingAs($this->user);
    $m3u = $this->get('/'.$this->playlist->uuid.'/playlist.m3u')->assertOk()->streamedContent();
    expect($target->fresh()->channel)->toBe(63)
        ->and($opposite->fresh()->getRawOriginal())->toBe($oppositeBefore)
        ->and($control->fresh()->getRawOriginal())->toBe($controlBefore)
        ->and(Channel::count())->toBe(3);
    expect($m3u)->toMatch('/tvg-chno="63"[^\n]*tvg-id="csv-29"/')
        ->toMatch('/tvg-chno="17"[^\n]*tvg-id="csv-17"/');
})->with(['live' => false, 'VOD' => true]);

it('preserves zero and false values through a normal quoted CSV roundtrip', function (bool $isVod) {
    $name = 'CSV, quoted "name"';
    $target = createCsvChannel($this->user, $this->playlist, $isVod, $name, 0);
    $control = createCsvChannel($this->user, $this->otherPlaylist, $isVod, $name, 53);
    $before = $control->fresh()->getRawOriginal();
    $page = $isVod ? ListVod::class : ListChannels::class;
    useCsvDatabaseQueue();
    Livewire::test($page)->mountAction('export')->fillForm(['playlist' => $this->playlist->id])
        ->callMountedAction()->assertHasNoFormErrors();
    drainCsvJobs();
    $this->actingAs($this->user);
    $export = Export::latest('id')->firstOrFail();
    $bytes = $this->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
        ->assertOk()->streamedContent();
    $reader = Reader::fromString($bytes);
    $reader->setHeaderOffset(0);
    $rows = iterator_to_array($reader->getRecords(), false);
    expect($rows)->toHaveCount(1)->and($rows[0]['Name'])->toBe($name)->and($rows[0]['Channel'])->toBe('0');

    foreach ([$bytes, writeChannelCsv([array_replace($rows[0], ['Enabled' => '0'])])] as $index => $input) {
        $this->actingAs($this->user);
        Livewire::test($page)->mountAction('import')->fillForm([
            'file' => UploadedFile::fake()->createWithContent('quoted.csv', $input),
            'playlist' => $this->playlist->id,
            'columnMap' => ['name' => 'Name', 'group' => 'Group', 'channel' => 'Channel', 'enabled' => 'Enabled'],
        ])->callMountedAction()->assertHasNoFormErrors();
        expect(drainCsvJobs(['is_vod' => $isVod, 'playlist' => $this->playlist->id]))->toContain(ImportCsv::class);
        expect(Import::latest('id')->firstOrFail()->successful_rows)->toBe(1)
            ->and($target->fresh()->channel)->toBe(0)
            ->and((bool) $target->fresh()->enabled)->toBe($index === 0)
            ->and($control->fresh()->getRawOriginal())->toBe($before);
        $this->actingAs($this->user);
        $m3u = $this->get('/'.$this->playlist->uuid.'/playlist.m3u')->assertOk()->streamedContent();
        if ($index === 0) {
            expect($m3u)->toContain('tvg-chno="0"');
        } else {
            expect($m3u)->not->toContain('tvg-id="csv-0"');
        }
    }
})->with(['live' => false, 'VOD' => true]);

it('does not fall back to the opposite type when the selected type has no match', function (bool $isVod) {
    $opposite = createCsvChannel($this->user, $this->playlist, ! $isVod, 'Opposite only', 17);
    $before = $opposite->fresh()->getRawOriginal();
    useCsvDatabaseQueue();
    Livewire::test($isVod ? ListVod::class : ListChannels::class)->mountAction('import')->fillForm([
        'file' => UploadedFile::fake()->createWithContent('opposite.csv', writeChannelCsv([
            ['Name' => 'Opposite only', 'Group' => 'CSV group', 'Channel' => '63', 'Enabled' => '1'],
        ])),
        'playlist' => $this->playlist->id,
        'columnMap' => ['name' => 'Name', 'group' => 'Group', 'channel' => 'Channel', 'enabled' => 'Enabled'],
    ])->callMountedAction()->assertHasNoFormErrors();
    expect(drainCsvJobs(['is_vod' => $isVod, 'playlist' => $this->playlist->id]))->toContain(ImportCsv::class);
    expect($opposite->fresh()->getRawOriginal())->toBe($before)->and(Channel::count())->toBe(1);
})->with(['live' => false, 'VOD' => true]);

it('requires channel mapping before dispatching import jobs', function (bool $isVod) {
    $target = createCsvChannel($this->user, $this->playlist, $isVod, 'Mapping subject', 8);
    useCsvDatabaseQueue();
    Livewire::test($isVod ? ListVod::class : ListChannels::class)->mountAction('import')->fillForm([
        'file' => UploadedFile::fake()->createWithContent('mapping.csv', writeChannelCsv([
            ['Name' => 'Mapping subject', 'Group' => 'CSV group', 'Channel' => '8', 'Enabled' => '1'],
        ])),
        'playlist' => $this->playlist->id,
        'columnMap' => ['name' => 'Name', 'group' => 'Group', 'channel' => null, 'enabled' => 'Enabled'],
    ])->callMountedAction()->assertHasFormErrors(['columnMap.channel' => 'required']);
    expect(Import::count())->toBe(0)->and(drainCsvJobs())->toBe([])->and($target->fresh()->channel)->toBe(8);
})->with(['live' => false, 'VOD' => true]);

it('keeps invalid and unmatched rows from changing or creating records', function (bool $isVod) {
    $target = createCsvChannel($this->user, $this->playlist, $isVod, 'Validation subject', 8);
    $before = $target->fresh()->getRawOriginal();
    useCsvDatabaseQueue();
    $input = writeChannelCsv([
        ['Name' => 'Validation subject', 'Group' => 'CSV group', 'Channel' => 'not-a-number', 'Enabled' => '1'],
        ['Name' => 'Validation subject', 'Group' => 'CSV group', 'Channel' => '-1', 'Enabled' => '1'],
        ['Name' => 'No existing match', 'Group' => 'CSV group', 'Channel' => '5', 'Enabled' => '1'],
    ]);
    Livewire::test($isVod ? ListVod::class : ListChannels::class)->mountAction('import')->fillForm([
        'file' => UploadedFile::fake()->createWithContent('invalid.csv', $input),
        'playlist' => $this->playlist->id,
        'columnMap' => ['name' => 'Name', 'group' => 'Group', 'channel' => 'Channel', 'enabled' => 'Enabled'],
    ])->callMountedAction()->assertHasNoFormErrors();
    expect(drainCsvJobs(['is_vod' => $isVod, 'playlist' => $this->playlist->id]))->toContain(ImportCsv::class);
    $import = Import::latest('id')->firstOrFail();
    expect($import->processed_rows)->toBe(3)->and($import->getFailedRowsCount())->toBe(2)
        ->and($target->fresh()->getRawOriginal())->toBe($before)->and(Channel::count())->toBe(1);
    $errors = $import->failedRows()->pluck('validation_error')->implode(' ');
    expect($errors)->toContain('at least 0');
    expect($import->failedRows()->pluck('data')->all())->toBe([
        ['Name' => 'Validation subject', 'Group' => 'CSV group', 'Channel' => 'not-a-number', 'Enabled' => '1'],
        ['Name' => 'Validation subject', 'Group' => 'CSV group', 'Channel' => '-1', 'Enabled' => '1'],
    ]);
})->with(['live' => false, 'VOD' => true]);
