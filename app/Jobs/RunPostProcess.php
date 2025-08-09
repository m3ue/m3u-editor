<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Facades\PlaylistUrlFacade;
use App\Mail\PostProcessMail;
use App\Models\Epg;
use App\Models\PostProcess;
use App\Models\PostProcessLog;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process as SymfonyProcess;
use Illuminate\Support\Str;

class RunPostProcess implements ShouldQueue
{
    use Queueable;

    // Make sure the process logs are cleaned up
    public int $maxLogs = 50;

    /**
     * Create a new job instance.
     * 
     * @param PostProcess $postProcess
     * @param Model $model
     */
    public function __construct(
        public PostProcess $postProcess,
        public Model $model,
        public ?Model $lastSync = null
    ) {
        // Merge the sync data with the model
        if ($lastSync) {
            $syncData = $lastSync->sync_stats;
            if ($syncData) {
                foreach ($syncData as $key => $value) {
                    // Only add if not already present in the model
                    if (!isset($this->model->{$key})) {
                        $this->model->{$key} = $value;
                    }
                }
            }
        }
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        $modelType = get_class($this->model);
        $name = $this->model->name;
        $user = $this->model->user;
        $status = $this->model->status;
        $postProcess = $this->postProcess;
        $metadata = $postProcess->metadata;
        $sendFailed = (bool)($metadata['send_failed'] ?? false);

        if ($status === Status::Failed && !$sendFailed) {
            // If the model status is failed and we don't want to execute the post process, then just return
            return;
        }

        // If last sync is available, we can use it to get the list of groups/channels added/removed
        // and include that in the email variables
        if ($this->lastSync) {
            // Add the counts of added/removed groups/channels to the model
            $this->model->added_groups = $this->lastSync->addedGroups()->count();
            $this->model->removed_groups = $this->lastSync->removedGroups()->count();
            $this->model->added_channels = $this->lastSync->addedChannels()->count();
            $this->model->removed_channels = $this->lastSync->removedChannels()->count();

            // Also add the names of the groups/channels added/removed
            $this->model->added_group_names = implode(' • ', $this->lastSync->addedGroups()->pluck('name')->toArray());
            $this->model->removed_group_names = implode(' • ', $this->lastSync->removedGroups()->pluck('name')->toArray());
            $this->model->added_channel_names = implode(' • ', $this->lastSync->addedChannels()->pluck('name')->toArray());
            $this->model->removed_channel_names = implode(' • ', $this->lastSync->removedChannels()->pluck('name')->toArray());
        }

        // Check if conditions are met before executing
        if (!$this->checkConditions()) {
            // Log that conditions were not met
            PostProcessLog::create([
                'name' => $name,
                'type' => $postProcess->event,
                'post_process_id' => $postProcess->id,
                'status' => 'skipped',
                'message' => 'Post process skipped: conditions not met',
            ]);
            return;
        }
        try {
            // See if calling webhook, or running a script
            // If the metadata is a URL, then we're calling a webhook
            if (str_contains($metadata['path'], '@')) {
                // Email processing
                $emailVars = [];
                $vars = $metadata['email_vars'] ?? [];
                foreach ($vars as $var) {
                    if ($var['value'] === 'url') {
                        if ($modelType === Epg::class) {
                            $value = route('epg.file', ['uuid' => $this->model->uuid]);
                        } else {
                            $value = PlaylistUrlFacade::getUrls($this->model)['m3u'];
                        }
                    } else {
                        if ($var['value'] === 'status') {
                            $value = $this->model->status->value ?? '';
                        } else {
                            $value = $this->model->{$var['value']} ?? '';
                        }
                    }
                    $emailVars[$var['value']] = $value;
                }

                // Send email using the configured email service
                $to = explode(',', $metadata['path']);
                Config::set('mail.default', 'smtp');
                Config::set('mail.from.address', $settings->smtp_from_address ?? 'no-reply@m3u-editor.dev');
                Config::set('mail.from.name', 'm3u editor');
                Config::set('mail.mailers.smtp.host', $settings->smtp_host);
                Config::set('mail.mailers.smtp.username', $settings->smtp_username);
                Config::set('mail.mailers.smtp.password', $settings->smtp_password);
                Config::set('mail.mailers.smtp.port', $settings->smtp_port);
                Config::set('mail.mailers.smtp.encryption', $settings->smtp_encryption);
                Mail::to($to)
                    ->send(new PostProcessMail(
                        emailSubject: $metadata['subject'] ?? "Sync completed for \"{$name}\"",
                        body: $metadata['body'] ?? "Sync completed for \"{$name}\". Please see below for details.",
                        variables: $emailVars,
                        user: $user
                    ));

                // Log that we've sent the email
                $title = "Post processing email for \"$name\"";
                $body = "Email sent with details.";
                PostProcessLog::create([
                    'name' => $name,
                    'type' => $postProcess->event,
                    'post_process_id' => $postProcess->id,
                    'status' => 'success',
                    'message' => $body,
                ]);
                Notification::make()
                    ->success()
                    ->title($title)
                    ->body($body)
                    ->broadcast($user)
                    ->sendToDatabase($user);
            } else if (str_starts_with($metadata['path'], 'http')) {
                // Using `post` as true/false; true = POST, false = GET
                $post = ((bool)$metadata['post']) ?? false;
                $method = $post ? 'post' : 'get';
                $url = $metadata['path'];
                $queryVars = [];
                $vars = $metadata['post_vars'] ?? [];
                foreach ($vars as $var) {
                    if ($var['value'] === 'url') {
                        if ($modelType === Epg::class) {
                            $value = route('epg.file', ['uuid' => $this->model->uuid]);
                        } else {
                            $value = PlaylistUrlFacade::getUrls($this->model)['m3u'];
                        }
                    } else {
                        $value = $this->model->{$var['value']} ?? '';
                    }
                    $queryVars[$var['variable_name']] = $value;
                }

                // Make the request
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                ])->throw()->$method($url, $queryVars);

                // If results ok, log the results
                if ($response->ok()) {
                    $title = "Post processing for \"$name\" completed successfully";
                    $body = $response->body() ?? '';
                    PostProcessLog::create([
                        'name' => $name,
                        'type' => $postProcess->event,
                        'post_process_id' => $postProcess->id,
                        'status' => 'success',
                        'message' => $body,
                    ]);
                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($body)
                        ->broadcast($user)
                        ->sendToDatabase($user);
                } else {
                    $title = "Error running post processing for \"$name\"";
                    $body = $response->body() ?? '';
                    PostProcessLog::create([
                        'name' => $name,
                        'type' => $postProcess->event,
                        'post_process_id' => $postProcess->id,
                        'status' => 'error',
                        'message' => $body,
                    ]);
                    Notification::make()
                        ->danger()
                        ->title($title)
                        ->body($body)
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            } else {
                // If the metadata is not a URL, then we're running a script
                $cmd = $metadata['path'];
                $process = SymfonyProcess::fromShellCommandline($cmd);
                $process->setTimeout(60);
                $exportVars = [];
                $vars = $metadata['script_vars'] ?? [];
                foreach ($vars as $var) {
                    if ($var['value'] === 'url') {
                        if ($modelType === Epg::class) {
                            $value = route('epg.file', ['uuid' => $this->model->uuid]);
                        } else {
                            $value = PlaylistUrlFacade::getUrls($this->model)['m3u'];
                        }
                    } else {
                        if ($var['value'] === 'status') {
                            $value = $this->model->status->value ?? '';
                        } else {
                            $value = $this->model->{$var['value']} ?? '';
                        }
                    }
                    $exportVars[$var['export_name']] = $value;
                }
                $output = '';
                $errors = '';
                if (count($exportVars) > 0) {
                    $process->setEnv($exportVars);
                }
                $hasErrors = false;
                $process->run(
                    function ($type, $buffer) use (&$output, &$hasErrors, &$errors) {
                        if ($type === SymfonyProcess::OUT) {
                            $output .= $buffer;
                        }
                        if ($type === SymfonyProcess::ERR) {
                            $hasErrors = true;
                            $errors .= $buffer;
                        }
                    }
                );

                // Check if the process was successful
                if (!$hasErrors) {
                    // Success!
                    $title = "Post processing for \"$name\" completed successfully";
                    $body = $output;
                    PostProcessLog::create([
                        'name' => $name,
                        'type' => $postProcess->event,
                        'post_process_id' => $postProcess->id,
                        'status' => 'success',
                        'message' => $body,
                    ]);
                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($body)
                        ->broadcast($user)
                        ->sendToDatabase($user);
                } else {
                    // Error running the script
                    $title = "Error running post processing for \"$name\"";
                    $body = $errors;
                    PostProcessLog::create([
                        'name' => $name,
                        'type' => $postProcess->event,
                        'post_process_id' => $postProcess->id,
                        'status' => 'error',
                        'message' => $body,
                    ]);
                    Notification::make()
                        ->danger()
                        ->title($title)
                        ->body($body)
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            }
        } catch (\Exception $e) {
            // Log the error
            $error = "Error running post processing for \"$name\": " . $e->getMessage();
            Log::error($error);
            PostProcessLog::create([
                'name' => $name,
                'type' => $postProcess->event,
                'post_process_id' => $postProcess->id,
                'status' => 'error',
                'message' => $error,
            ]);
            Notification::make()
                ->danger()
                ->title("Error running post processing for \"$name\"")
                ->body('Please view your notifications for details.')
                ->broadcast($user);
            Notification::make()
                ->danger()
                ->title("Error running post processing for \"$name\"")
                ->body($error)
                ->sendToDatabase($user);
        } finally {
            // Clean up logs to make sure we don't have too many...
            $logsQuery = $postProcess->logs();
            if ($logsQuery->count() > $this->maxLogs) {
                $logsQuery
                    ->orderBy('created_at', 'asc')
                    ->limit($logsQuery->count() - $this->maxLogs)
                    ->delete();
            }
        }
    }

    /**
     * Check if all conditions are met for this post process
     */
    protected function checkConditions(): bool
    {
        $conditions = $this->postProcess->conditions;

        // If no conditions are set, allow execution
        if (empty($conditions)) {
            return true;
        }

        // Check each condition - all must be true for execution
        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Evaluate a single condition
     */
    protected function evaluateCondition(array $condition): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        $expectedValue = $condition['value'] ?? null;

        if (!$field || !$operator) {
            return false;
        }

        // Get the actual value from the model
        $actualValue = $this->getFieldValue($field);
        return match ($operator) {
            'equals' => $actualValue == $expectedValue,
            'not_equals' => $actualValue != $expectedValue,
            'greater_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue > $expectedValue,
            'less_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue < $expectedValue,
            'greater_than_or_equal' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue >= $expectedValue,
            'less_than_or_equal' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue <= $expectedValue,
            'contains' => is_string($actualValue) && is_string($expectedValue) && str_contains($actualValue, $expectedValue),
            'not_contains' => is_string($actualValue) && is_string($expectedValue) && !str_contains($actualValue, $expectedValue),
            'starts_with' => is_string($actualValue) && is_string($expectedValue) && str_starts_with($actualValue, $expectedValue),
            'ends_with' => is_string($actualValue) && is_string($expectedValue) && str_ends_with($actualValue, $expectedValue),
            'is_true' => (bool)$actualValue === true,
            'is_false' => (bool)$actualValue === false,
            'is_empty' => empty($actualValue),
            'is_not_empty' => !empty($actualValue),
            default => false,
        };
    }

    /**
     * Get the value of a field from the model or related data
     */
    protected function getFieldValue(string $field): mixed
    {
        return match ($field) {
            'id' => $this->model->id,
            'uuid' => $this->model->uuid,
            'name' => $this->model->name,
            'url' => $this->model->url ?? null,
            'status' => $this->model->status?->value ?? $this->model->status,
            'synctime' => $this->model->sync_time,
            'added_groups' => $this->model->added_groups ?? null,
            'removed_groups' => $this->model->removed_groups ?? null,
            'added_channels' => $this->model->added_channels ?? null,
            'removed_channels' => $this->model->removed_channels ?? null,
            'added_group_names' => $this->model->added_group_names ?? null,
            'removed_group_names' => $this->model->removed_group_names ?? null,
            'added_channel_names' => $this->model->added_channel_names ?? null,
            'removed_channel_names' => $this->model->removed_channel_names ?? null,
            default => null,
        };
    }
}
