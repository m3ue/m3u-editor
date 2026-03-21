<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Series;
use App\Services\TmdbService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class TmdbSearch extends Component
{
    public bool $showModal = false;

    public string $searchQuery = '';

    public ?int $searchYear = null;

    public string $searchType = 'tv'; // 'tv' or 'movie'

    public ?int $recordId = null;

    public ?string $recordType = null; // 'series' or 'vod'

    public array $results = [];

    public bool $isSearching = false;

    public ?string $originalTitle = null;

    #[On('openTmdbSearch')]
    public function openSearch(array $data = []): void
    {
        $this->searchQuery = $data['query'] ?? '';
        $this->searchYear = $data['year'] ?? null;
        $this->searchType = $data['type'] ?? 'tv';
        $this->recordId = $data['recordId'] ?? null;
        $this->recordType = $data['recordType'] ?? null;
        $this->originalTitle = $data['originalTitle'] ?? null;
        $this->results = [];
        $this->showModal = true;

        Log::debug('TmdbSearch modal opened', [
            'query' => $this->searchQuery,
            'year' => $this->searchYear,
            'type' => $this->searchType,
            'recordId' => $this->recordId,
            'recordType' => $this->recordType,
        ]);
    }

    public function search(): void
    {
        if (empty($this->searchQuery)) {
            return;
        }

        $this->isSearching = true;

        try {
            $tmdbService = app(TmdbService::class);

            if ($this->searchType === 'tv') {
                $this->results = $tmdbService->searchTvSeriesManual(
                    $this->searchQuery,
                    $this->searchYear
                );
            } else {
                $this->results = $tmdbService->searchMovieManual(
                    $this->searchQuery,
                    $this->searchYear
                );
            }

            Log::debug('TmdbSearch results', [
                'query' => $this->searchQuery,
                'type' => $this->searchType,
                'count' => count($this->results),
            ]);
        } catch (\Exception $e) {
            Log::error('TmdbSearch error', [
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Search Error')
                ->body('An error occurred while searching TMDB.')
                ->send();
        }

        $this->isSearching = false;
    }

    public function selectResult(int $tmdbId): void
    {
        if (! $this->recordId || ! $this->recordType) {
            Notification::make()
                ->danger()
                ->title('Error')
                ->body('No record selected to update.')
                ->send();

            return;
        }

        try {
            $tmdbService = app(TmdbService::class);

            if ($this->searchType === 'tv') {
                $metadata = $tmdbService->applyTvSeriesSelection($tmdbId);

                if ($metadata && $this->recordType === 'series') {
                    $series = Series::find($this->recordId);
                    if ($series) {
                        $series->update([
                            'tmdb_id' => $metadata['tmdb_id'],
                            'tvdb_id' => $metadata['tvdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        Log::info('TmdbSearch: Manually applied TMDB IDs to series', [
                            'series_id' => $series->id,
                            'series_name' => $series->name,
                            'tmdb_id' => $metadata['tmdb_id'],
                            'tvdb_id' => $metadata['tvdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('TMDB IDs Applied')
                            ->body("Successfully linked \"{$series->name}\" to \"{$metadata['name']}\" (TMDB: {$metadata['tmdb_id']})")
                            ->send();

                        $this->closeModal();
                        $this->dispatch('refresh');

                        return;
                    }
                }
            } else {
                $metadata = $tmdbService->applyMovieSelection($tmdbId);

                if ($metadata && $this->recordType === 'vod') {
                    $vod = Channel::find($this->recordId);
                    if ($vod) {
                        // VOD stores TMDB IDs in the 'info' JSON field
                        $info = $vod->info ?? [];
                        $info['tmdb_id'] = $metadata['tmdb_id'];
                        $info['imdb_id'] = $metadata['imdb_id'];

                        $vod->update([
                            'info' => $info,
                        ]);

                        Log::info('TmdbSearch: Manually applied TMDB IDs to VOD', [
                            'vod_id' => $vod->id,
                            'vod_name' => $vod->name ?? $vod->title,
                            'tmdb_id' => $metadata['tmdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        $vodName = $vod->title_custom ?: $vod->title ?: $vod->name;
                        Notification::make()
                            ->success()
                            ->title('TMDB IDs Applied')
                            ->body("Successfully linked \"{$vodName}\" to \"{$metadata['title']}\" (TMDB: {$metadata['tmdb_id']})")
                            ->send();

                        $this->closeModal();
                        $this->dispatch('refresh');

                        return;
                    }
                }
            }

            Notification::make()
                ->danger()
                ->title('Error')
                ->body('Failed to apply TMDB selection.')
                ->send();
        } catch (\Exception $e) {
            Log::error('TmdbSearch selection error', [
                'error' => $e->getMessage(),
                'tmdb_id' => $tmdbId,
            ]);

            Notification::make()
                ->danger()
                ->title('Error')
                ->body('An error occurred: '.$e->getMessage())
                ->send();
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->results = [];
        $this->searchQuery = '';
        $this->searchYear = null;
        $this->recordId = null;
        $this->recordType = null;
        $this->originalTitle = null;
    }

    public function render()
    {
        return view('livewire.tmdb-search');
    }
}
