<?php

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use App\Services\NetworkScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * Helper: create a Channel with a known duration.
 */
function makeChainChannel(int $durationSeconds = 1800): Channel
{
    return Channel::factory()->create([
        'is_vod' => true,
        'info' => ['duration_secs' => $durationSeconds],
    ]);
}

/**
 * Helper: add a channel to a network as NetworkContent.
 *
 * @param  array<string, mixed>  $attrs
 */
function addChainContent(Network $network, Channel $channel, array $attrs = []): NetworkContent
{
    return NetworkContent::withoutEvents(function () use ($network, $channel, $attrs) {
        return NetworkContent::create(array_merge([
            'network_id' => $network->id,
            'contentable_type' => Channel::class,
            'contentable_id' => $channel->id,
            'sort_order' => 1,
            'weight' => 1,
        ], $attrs));
    });
}

/**
 * Helper: the ordered sequence of contentable_id from a network's generated programmes.
 *
 * @return array<int, int>
 */
function programmeContentableIds(Network $network): array
{
    return NetworkProgramme::where('network_id', $network->id)
        ->orderBy('start_time')
        ->pluck('contentable_id')
        ->all();
}

it('isChained returns false when chain_id is null, true when set', function () {
    $nc = new NetworkContent(['chain_id' => null]);
    expect($nc->isChained())->toBeFalse();

    $nc->chain_id = 5;
    expect($nc->isChained())->toBeTrue();
});

it('chainMembers returns all rows sharing chain_id ordered by sort_order', function () {
    $network = Network::factory()->create(['schedule_type' => 'shuffle', 'auto_regenerate_schedule' => false]);

    $a = addChainContent($network, makeChainChannel(), ['sort_order' => 3]);
    $b = addChainContent($network, makeChainChannel(), ['sort_order' => 1, 'chain_id' => $a->id]);
    $c = addChainContent($network, makeChainChannel(), ['sort_order' => 2, 'chain_id' => $a->id]);
    $a->update(['chain_id' => $a->id]);

    $members = $a->chainMembers();

    expect($members->pluck('id')->all())->toBe([$b->id, $c->id, $a->id]);
});

it('chainLead resolves the lowest-sort_order member, not the row whose id equals chain_id', function () {
    $network = Network::factory()->create(['schedule_type' => 'shuffle', 'auto_regenerate_schedule' => false]);

    $a = addChainContent($network, makeChainChannel(), ['sort_order' => 5]);
    $b = addChainContent($network, makeChainChannel(), ['sort_order' => 1, 'chain_id' => $a->id]);
    $a->update(['chain_id' => $a->id]);

    // chain_id equals $a->id, but $b has the lower sort_order — $b is the lead.
    expect($a->chainLead()->id)->toBe($b->id)
        ->and($b->chainLead()->id)->toBe($b->id);
});

it('chainMembers and chainLead on an unchained item return itself', function () {
    $network = Network::factory()->create(['schedule_type' => 'shuffle', 'auto_regenerate_schedule' => false]);
    $nc = addChainContent($network, makeChainChannel());

    expect($nc->chainMembers()->pluck('id')->all())->toBe([$nc->id])
        ->and($nc->chainLead()->id)->toBe($nc->id);
});

it('keeps chained items consecutive in shuffle mode across several distinct weeks', function () {
    $weeks = ['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'];

    foreach ($weeks as $week) {
        Carbon::setTestNow(Carbon::parse($week.' 08:00:00'));

        $network = Network::factory()->create([
            'schedule_type' => 'shuffle',
            'loop_content' => true,
            'schedule_window_days' => 1,
            'auto_regenerate_schedule' => false,
        ]);

        $a = addChainContent($network, makeChainChannel(60), ['sort_order' => 1]);
        $b = addChainContent($network, makeChainChannel(60), ['sort_order' => 2]);
        $c = addChainContent($network, makeChainChannel(60), ['sort_order' => 3]);
        $a->update(['chain_id' => $a->id]);
        $b->update(['chain_id' => $a->id]);
        $c->update(['chain_id' => $a->id]);

        // Filler so the chain isn't the only content in the pool.
        addChainContent($network, makeChainChannel(60), ['sort_order' => 4]);
        addChainContent($network, makeChainChannel(60), ['sort_order' => 5]);

        app(NetworkScheduleService::class)->generateSchedule($network);

        $sequence = programmeContentableIds($network);
        $chainIds = [$a->contentable_id, $b->contentable_id, $c->contentable_id];

        // Every occurrence of the chain's lead must be immediately followed by
        // b then c, in that order, with nothing else interleaved.
        foreach ($sequence as $index => $contentableId) {
            if ($contentableId === $a->contentable_id) {
                expect(array_slice($sequence, $index, 3))->toBe($chainIds);
            }
        }

        expect($sequence)->not->toBeEmpty();
    }

    Carbon::setTestNow();
});

it('applies the chain leads weight to the whole chain as one shuffle unit', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'shuffle',
        'loop_content' => true,
        'schedule_window_days' => 1,
        'auto_regenerate_schedule' => false,
    ]);

    // Chain: lead weight 3, second member's own weight is irrelevant once chained.
    $lead = addChainContent($network, makeChainChannel(60), ['sort_order' => 1, 'weight' => 3]);
    $second = addChainContent($network, makeChainChannel(60), ['sort_order' => 2, 'weight' => 1]);
    $second->update(['chain_id' => $lead->id]);
    $lead->update(['chain_id' => $lead->id]);

    // Unchained item, weight 1 — baseline frequency.
    $lone = addChainContent($network, makeChainChannel(60), ['sort_order' => 3, 'weight' => 1]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    $chainOccurrences = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $lead->contentable_id)
        ->count();

    $loneOccurrences = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $lone->contentable_id)
        ->count();

    expect($loneOccurrences)->toBeGreaterThan(0);

    $ratio = $chainOccurrences / $loneOccurrences;
    expect($ratio)->toBeGreaterThan(2.5)->toBeLessThan(3.5);

    Carbon::setTestNow();
});

it('unchained items do not always land adjacent to each other across different weeks', function () {
    $weeks = ['2026-02-02', '2026-02-09', '2026-02-16', '2026-02-23', '2026-03-02', '2026-03-09'];
    $adjacentCount = 0;

    foreach ($weeks as $week) {
        Carbon::setTestNow(Carbon::parse($week.' 08:00:00'));

        $network = Network::factory()->create([
            'schedule_type' => 'shuffle',
            'loop_content' => true,
            'schedule_window_days' => 1,
            'auto_regenerate_schedule' => false,
        ]);

        $x = addChainContent($network, makeChainChannel(60), ['sort_order' => 1]);
        $y = addChainContent($network, makeChainChannel(60), ['sort_order' => 2]);
        foreach (range(3, 6) as $i) {
            addChainContent($network, makeChainChannel(60), ['sort_order' => $i]);
        }

        app(NetworkScheduleService::class)->generateSchedule($network);

        $sequence = programmeContentableIds($network);
        $xPos = array_search($x->contentable_id, $sequence, true);
        $yPos = array_search($y->contentable_id, $sequence, true);

        if (abs($xPos - $yPos) === 1) {
            $adjacentCount++;
        }
    }

    // Unchained same-weight items should not be glued together every single week.
    expect($adjacentCount)->toBeLessThan(count($weeks));

    Carbon::setTestNow();
});

it('deleting the lead member of a chain does not orphan or crash scheduling', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'shuffle',
        'loop_content' => true,
        'schedule_window_days' => 1,
        'auto_regenerate_schedule' => false,
    ]);

    $a = addChainContent($network, makeChainChannel(60), ['sort_order' => 1]);
    $b = addChainContent($network, makeChainChannel(60), ['sort_order' => 2]);
    $c = addChainContent($network, makeChainChannel(60), ['sort_order' => 3]);
    $a->update(['chain_id' => $a->id]);
    $b->update(['chain_id' => $a->id]);
    $c->update(['chain_id' => $a->id]);
    addChainContent($network, makeChainChannel(60), ['sort_order' => 4]);

    $a->delete();

    app(NetworkScheduleService::class)->generateSchedule($network, forceReset: true);

    $sequence = programmeContentableIds($network);
    $bPos = array_search($b->contentable_id, $sequence, true);
    $cPos = array_search($c->contentable_id, $sequence, true);

    expect($bPos)->not->toBeFalse()
        ->and($cPos)->toBe($bPos + 1);

    Carbon::setTestNow();
});

it('deleting a chain member down to one survivor auto-clears its chain_id', function () {
    $network = Network::factory()->create(['schedule_type' => 'shuffle', 'auto_regenerate_schedule' => false]);

    $a = addChainContent($network, makeChainChannel(), ['sort_order' => 1]);
    $b = addChainContent($network, makeChainChannel(), ['sort_order' => 2]);
    $a->update(['chain_id' => $a->id]);
    $b->update(['chain_id' => $a->id]);

    $a->delete();

    expect($b->fresh()->chain_id)->toBeNull();
});
