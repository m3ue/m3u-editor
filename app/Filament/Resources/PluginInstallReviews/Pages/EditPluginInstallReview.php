<?php

namespace App\Filament\Resources\PluginInstallReviews\Pages;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\PluginInstallReview;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditPluginInstallReview extends EditRecord
{
    protected static string $resource = PluginInstallReviewResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record;
    }

    protected function getHeaderActions(): array
    {
        /** @var PluginInstallReview $record */
        $record = $this->record;

        return [
            ActionGroup::make([
                Action::make('scan')
                    ->label(__('Run ClamAV Scan'))
                    ->icon('heroicon-o-shield-check')
                    ->hidden(fn () => PluginInstallReviewResource::useFakeScanner())
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->scanInstallReview($record);

                            Notification::make()
                                ->title(__('Scan completed'))
                                ->body($review->scan_summary ?: "Scan status: {$review->scan_status}")
                                ->color($review->scan_status === 'clean' ? 'success' : 'warning')
                                ->send();

                            $this->refreshFormData([
                                'status',
                                'scan_status',
                                'scan_summary',
                                'scan_details_json',
                            ]);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Scan failed'))
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('approve')
                    ->label(__('Install'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->approveInstallReview($record, false, auth()->id());

                            Notification::make()
                                ->success()
                                ->title(__('Plugin installed'))
                                ->body(__("Plugin install #:id installed [:plugin_id].", ['id' => $review->id, 'plugin_id' => $review->plugin_id]))
                                ->send();

                            $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Plugin install failed'))
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('install_and_trust')
                    ->label(__('Install And Trust'))
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->approveInstallReview($record, true, auth()->id());

                            Notification::make()
                                ->success()
                                ->title(__('Plugin installed and trusted'))
                                ->body(__("Plugin install #:id installed and trusted [:plugin_id].", ['id' => $review->id, 'plugin_id' => $review->plugin_id]))
                                ->send();

                            $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Plugin install failed'))
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('reject')
                    ->label(__('Reject Install'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            $review = app(PluginManager::class)->rejectInstallReview($record, auth()->id());

                            Notification::make()
                                ->success()
                                ->title(__('Plugin install rejected'))
                                ->body(__("Plugin install #:id was rejected.", ['id' => $review->id]))
                                ->send();

                            $this->refreshFormData(['status', 'review_notes']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Reject install failed'))
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('discard')
                    ->label(__('Discard Review'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden(fn () => $record->status === 'installed')
                    ->requiresConfirmation()
                    ->action(function () use ($record): void {
                        try {
                            app(PluginManager::class)->discardInstallReview($record);

                            Notification::make()
                                ->success()
                                ->title(__('Plugin install discarded'))
                                ->send();

                            $this->redirect(PluginInstallReviewResource::getUrl());
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Discard review failed'))
                                ->body($exception->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])->label(__('Actions'))->button(),
            DeleteAction::make()
                ->label(__('Delete Record'))
                ->modalDescription(__('Permanently removes this install log entry. The plugin itself (if installed) is not affected.'))
                ->successRedirectUrl(PluginInstallReviewResource::getUrl()),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
