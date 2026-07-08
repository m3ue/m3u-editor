<?php

use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\RelationManagers\NetworkContentRelationManager;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @param  array<string, mixed>  $attrs
 */
function makeChainRmContent(Network $network, array $attrs = []): NetworkContent
{
    $channel = Channel::factory()->create();

    return NetworkContent::withoutEvents(fn () => NetworkContent::create(array_merge([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ], $attrs)));
}

it('shows link and unlink chain bulk actions on the content table', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])
        ->assertTableBulkActionExists('linkAsChain')
        ->assertTableBulkActionExists('unlinkChain');
});

it('sets a shared chain_id on selected records via linkAsChain', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    $a = makeChainRmContent($network, ['sort_order' => 2]);
    $b = makeChainRmContent($network, ['sort_order' => 1]);
    $c = makeChainRmContent($network, ['sort_order' => 3]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('linkAsChain', [$a, $b, $c]);

    $a->refresh();
    $b->refresh();
    $c->refresh();

    expect($a->chain_id)->not->toBeNull()
        ->and($a->chain_id)->toBe($b->chain_id)
        ->and($a->chain_id)->toBe($c->chain_id)
        // b has the lowest sort_order among the selection, so it becomes the token.
        ->and($a->chain_id)->toBe($b->id);
});

it('does not chain a selection of fewer than 2 records', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);
    $a = makeChainRmContent($network);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('linkAsChain', [$a]);

    expect($a->fresh()->chain_id)->toBeNull();
});

it('rejects linking a selection containing a pinned item', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    $a = makeChainRmContent($network);
    $pinned = makeChainRmContent($network, [
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('linkAsChain', [$a, $pinned]);

    expect($a->fresh()->chain_id)->toBeNull()
        ->and($pinned->fresh()->chain_id)->toBeNull();
});

it('vacates and singleton-cleans the old chain when re-chaining a member elsewhere', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    // Chain A: three members.
    $a1 = makeChainRmContent($network, ['sort_order' => 1]);
    $a2 = makeChainRmContent($network, ['sort_order' => 2]);
    $a3 = makeChainRmContent($network, ['sort_order' => 3]);
    $a1->update(['chain_id' => $a1->id]);
    $a2->update(['chain_id' => $a1->id]);
    $a3->update(['chain_id' => $a1->id]);

    // Pull a3 into a brand-new chain with an unrelated item.
    $other = makeChainRmContent($network, ['sort_order' => 4]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('linkAsChain', [$a3, $other]);

    // Old chain A had 2 members left (a1, a2) — singleton rule doesn't apply
    // since 2 remain, so they should still be chained together.
    expect($a1->fresh()->chain_id)->toBe($a2->fresh()->chain_id)
        ->and($a3->fresh()->chain_id)->toBe($other->fresh()->chain_id)
        ->and($a1->fresh()->chain_id)->not->toBe($a3->fresh()->chain_id);
});

it('clears chain_id on selected records via unlinkChain', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    $a = makeChainRmContent($network, ['sort_order' => 1]);
    $b = makeChainRmContent($network, ['sort_order' => 2]);
    $c = makeChainRmContent($network, ['sort_order' => 3]);
    $a->update(['chain_id' => $a->id]);
    $b->update(['chain_id' => $a->id]);
    $c->update(['chain_id' => $a->id]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('unlinkChain', [$a, $b, $c]);

    expect($a->fresh()->chain_id)->toBeNull()
        ->and($b->fresh()->chain_id)->toBeNull()
        ->and($c->fresh()->chain_id)->toBeNull();
});

it('leaves unchained records untouched when unlinkChain is called on a mixed selection', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);

    $a = makeChainRmContent($network, ['sort_order' => 1]);
    $b = makeChainRmContent($network, ['sort_order' => 2]);
    $a->update(['chain_id' => $a->id]);
    $b->update(['chain_id' => $a->id]);

    $unchained = makeChainRmContent($network, ['sort_order' => 3]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->callTableBulkAction('unlinkChain', [$a, $b, $unchained]);

    expect($a->fresh()->chain_id)->toBeNull()
        ->and($b->fresh()->chain_id)->toBeNull()
        ->and($unchained->fresh()->chain_id)->toBeNull();
});
