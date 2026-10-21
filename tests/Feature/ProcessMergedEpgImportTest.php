<?php

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Bus::fake();
    Event::fake([SyncCompleted::class]);
    $this->user = User::factory()->create();
    $this->merged = Epg::factory()->for($this->user)->createQuietly([
        'name' => 'Synthetic merged guide',
        'is_merged' => true,
        'url' => null,
    ]);
    $this->sources = collect(['first.test', 'second.test'])->map(function (string $channelId, int $index) {
        $source = Epg::factory()->for($this->user)->createQuietly([
            'url' => "https://guide.example.test/{$channelId}.xml",
        ]);
        $this->merged->sourceEpgs()->attach($source->id, ['sort_order' => $index]);
        Storage::disk('local')->put($source->file_path, '<?xml version="1.0"?><tv>'
            .'<channel id="'.$channelId.'"><display-name>'.$channelId.'</display-name></channel>'
            .'<programme channel="'.$channelId.'" start="20260101000000 +0000" stop="20260101010000 +0000"><title>Synthetic programme</title></programme>'
            .'</tv>');

        return $source;
    });
    $this->runMerge = function () {
        (new ProcessEpgImport($this->merged->fresh(), force: true))->handle(app(SchedulesDirectService::class));
        $this->merged->refresh();
    };
});

it('preserves the last good merged download when every selected source file is missing', function () {
    ($this->runMerge)();
    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->channel_count)->toBe(2)
        ->and($this->merged->programme_count)->toBe(2);
    $original = Storage::disk('local')->get($this->merged->file_path);
    $this->get(route('epg.file', $this->merged->uuid))->assertOk()->assertStreamedContent($original);

    foreach ($this->sources as $source) {
        Storage::disk('local')->delete($source->file_path);
    }
    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Failed)
        ->and($this->merged->processing)->toBeFalse()
        ->and($this->merged->errors)->toStartWith('Unable to build merged EPG.')
        ->and($this->merged->channel_count)->toBe(2)
        ->and($this->merged->programme_count)->toBe(2)
        ->and(Storage::disk('local')->get($this->merged->file_path))->toBe($original)
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
    $this->get(route('epg.file', $this->merged->uuid))->assertOk()->assertStreamedContent($original);
});

it('preserves the last good guide when no selected source can be parsed as complete XMLTV', function (string $contents) {
    ($this->runMerge)();
    $original = Storage::disk('local')->get($this->merged->file_path);
    foreach ($this->sources as $source) {
        Storage::disk('local')->put($source->file_path, $contents);
    }

    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Failed)
        ->and($this->merged->errors)->not->toBeNull()
        ->and($this->merged->channel_count)->toBe(2)
        ->and($this->merged->programme_count)->toBe(2)
        ->and(Storage::disk('local')->get($this->merged->file_path))->toBe($original)
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
})->with([
    'empty file' => '',
    'HTML error' => '<html><body>Service unavailable</body></html>',
    'truncated XMLTV' => '<tv><channel id="broken.test"><display-name>Broken</display-name></channel>',
]);

it('keeps merging the complete source when another source is empty or not XMLTV', function (string $contents) {
    Storage::disk('local')->put($this->sources->first()->file_path, $contents);

    ($this->runMerge)();

    $xml = simplexml_load_string(Storage::disk('local')->get($this->merged->file_path));
    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->channel_count)->toBe(1)
        ->and((string) $xml->channel['id'])->toBe('second.test')
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
})->with([
    'empty file' => '',
    'HTML error' => '<html><body>Service unavailable</body></html>',
]);

it('reports the configuration error and keeps the guide when no source EPGs are selected', function () {
    ($this->runMerge)();
    $original = Storage::disk('local')->get($this->merged->file_path);
    $this->merged->sourceEpgs()->detach();

    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Failed)
        ->and($this->merged->errors)->toStartWith('Invalid merged EPG configuration.')
        ->and(Storage::disk('local')->get($this->merged->file_path))->toBe($original);
});

it('keeps the old download visible until the replacement is promoted and cleans up a failed promotion', function () {
    ($this->runMerge)();
    $original = Storage::disk('local')->get($this->merged->file_path);

    File::partialMock()->shouldReceive('move')->once()->andReturnUsing(function (string $temporary, string $target) use ($original): bool {
        expect($target)->toBe(Storage::disk('local')->path($this->merged->file_path))
            ->and(dirname($temporary))->toBe(dirname($target))
            ->and(file_get_contents($target))->toBe($original)
            ->and(count(simplexml_load_file($temporary)->channel))->toBe(2);

        return false;
    });
    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Failed)
        ->and($this->merged->channel_count)->toBe(2)
        ->and($this->merged->programme_count)->toBe(2)
        ->and(Storage::disk('local')->get($this->merged->file_path))->toBe($original)
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
});

it('continues merging the available source when another source file is missing', function () {
    Storage::disk('local')->delete($this->sources->last()->file_path);

    ($this->runMerge)();

    $xml = simplexml_load_string(Storage::disk('local')->get($this->merged->file_path));
    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->channel_count)->toBe(1)
        ->and($this->merged->programme_count)->toBe(1)
        ->and((string) $xml->channel['id'])->toBe('first.test');
});

it('continues accepting a readable empty XMLTV source', function (string $contents) {
    Storage::disk('local')->delete($this->sources->last()->file_path);
    Storage::disk('local')->put($this->sources->first()->file_path, $contents);

    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->channel_count)->toBe(0)
        ->and($this->merged->programme_count)->toBe(0);
})->with(['self-closing' => '<tv/>', 'explicit closing tag' => '<tv></tv>']);

it('merges compressed source guides and keeps the first source for duplicate channel ids', function () {
    $primary = '<tv><channel id="shared.test"><display-name>Primary</display-name></channel></tv>';
    $secondary = '<tv><channel id="shared.test"><display-name>Secondary</display-name></channel></tv>';
    Storage::disk('local')->put($this->sources->first()->file_path, gzencode($primary));
    Storage::disk('local')->put($this->sources->last()->file_path, $secondary);

    ($this->runMerge)();

    $xml = simplexml_load_string(Storage::disk('local')->get($this->merged->file_path));
    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->channel_count)->toBe(1)
        ->and((string) $xml->channel->{'display-name'})->toBe('Primary')
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
});

it('preserves the guide through repeated missing-source failures and recovers when sources return', function () {
    ($this->runMerge)();
    $original = Storage::disk('local')->get($this->merged->file_path);
    $sources = $this->sources->mapWithKeys(fn (Epg $epg): array => [$epg->file_path => Storage::disk('local')->get($epg->file_path)]);
    foreach ($sources as $path => $contents) {
        Storage::disk('local')->delete($path);
    }
    foreach (range(1, 2) as $attempt) {
        ($this->runMerge)();
        expect($this->merged->status)->toBe(Status::Failed)
            ->and(Storage::disk('local')->get($this->merged->file_path))->toBe($original);
    }
    foreach ($sources as $path => $contents) {
        Storage::disk('local')->put($path, str_replace('Synthetic programme', 'Updated programme', $contents));
    }

    ($this->runMerge)();

    expect($this->merged->status)->toBe(Status::Completed)
        ->and($this->merged->errors)->toBeNull()
        ->and($this->merged->channel_count)->toBe(2)
        ->and($this->merged->programme_count)->toBe(2)
        ->and(Storage::disk('local')->get($this->merged->file_path))->toContain('Updated programme')
        ->and(Storage::disk('local')->files($this->merged->folder_path))->toBe([$this->merged->file_path]);
});
