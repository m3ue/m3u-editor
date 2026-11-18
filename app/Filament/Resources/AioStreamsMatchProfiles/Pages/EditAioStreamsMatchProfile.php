<?php

namespace App\Filament\Resources\AioStreamsMatchProfiles\Pages;

use App\Filament\Actions\AioStreamsRescanAction;
use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\AioStreamsCandidateService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;

class EditAioStreamsMatchProfile extends EditRecord
{
    protected static string $resource = AioStreamsMatchProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->testProfileAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Dry-run the form's current rules (unsaved edits included) against one
     * of the user's AIOStreams titles, showing how each stream is tagged and
     * whether it would be the primary, a failover or excluded (and why). A
     * wizard for the same reason as the rescan action: the stream fetch is
     * slow, so it runs on "Next" rather than from a live field.
     */
    protected function testProfileAction(): Action
    {
        return Action::make('testProfile')
            ->label(__('Test profile'))
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->modalHeading(__('Test profile'))
            ->modalDescription(__('See how the rules on this page, including unsaved changes, rank the streams AIOStreams offers for one of your titles right now. Nothing is changed.'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('Done'))
            ->steps([
                Step::make('title')
                    ->label(__('Title'))
                    ->schema([
                        Select::make('item')
                            ->label(__('Title'))
                            ->helperText(__('Movies and episodes added to your library from AIOStreams.'))
                            ->searchable()
                            ->options(fn (): array => self::titleOptions(''))
                            ->getSearchResultsUsing(fn (string $search): array => self::titleOptions($search))
                            ->required(),
                    ])
                    ->afterValidation(function (Get $get, Set $set): void {
                        $item = self::resolveTitle((string) $get('item'));

                        try {
                            $rows = $item ? app(AioStreamsCandidateService::class)->previewRules($item, $this->data['rules'] ?? []) : [];
                        } catch (\Throwable $e) {
                            Log::warning('Test AIOStreams profile: fetching streams failed', ['exception' => $e->getMessage()]);
                            $rows = [];
                        }

                        if ($rows === []) {
                            Notification::make()->danger()->title(__('No streams found for this title right now'))->send();

                            throw new Halt;
                        }

                        $set('results', $rows);
                    }),
                Step::make('results')
                    ->label(__('Results'))
                    ->schema([
                        Repeater::make('results')
                            ->hiddenLabel()
                            ->schema([
                                Text::make(fn (Get $get): string => (string) $get('outcome'))
                                    ->badge()
                                    ->color(fn (Get $get): string => filled($get('excluded')) ? 'danger' : 'success'),
                                // The outcome badge above already says why a stream is excluded.
                                ...AioStreamsRescanAction::candidateInfo(showExclusion: false),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible(),
                    ]),
            ])
            ->action(fn () => null);
    }

    /**
     * @return array<string, string>
     */
    protected static function titleOptions(string $search): array
    {
        $movies = Channel::query()
            ->where('user_id', auth()->id())
            ->where('is_custom', true)
            ->where('is_vod', true)
            ->whereNotNull('aio_integration_id')
            ->when($search !== '', fn ($query) => $query->whereLike('title', "%{$search}%", caseSensitive: false))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'title', 'name'])
            ->mapWithKeys(fn (Channel $channel) => ["channel:{$channel->id}" => __('Movie: :title', ['title' => $channel->title ?? $channel->name])]);

        $episodes = Episode::query()
            ->with('series:id,name')
            ->where('user_id', auth()->id())
            ->where('is_custom', true)
            ->whereNotNull('aio_item_id')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('title', "%{$search}%", caseSensitive: false)
                ->orWhereHas('series', fn ($query) => $query->whereLike('name', "%{$search}%", caseSensitive: false))))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'series_id', 'season', 'episode_num', 'title'])
            ->mapWithKeys(fn (Episode $episode) => ["episode:{$episode->id}" => sprintf(
                '%s S%02dE%02d',
                $episode->series?->name ?? $episode->title,
                (int) $episode->season,
                (int) $episode->episode_num,
            )]);

        return $movies->merge($episodes)->all();
    }

    protected static function resolveTitle(string $value): Channel|Episode|null
    {
        [$type, $id] = array_pad(explode(':', $value, 2), 2, null);

        return match ($type) {
            'channel' => Channel::query()->where('user_id', auth()->id())->whereNotNull('aio_integration_id')->find($id),
            'episode' => Episode::query()->where('user_id', auth()->id())->whereNotNull('aio_item_id')->find($id),
            default => null,
        };
    }
}
