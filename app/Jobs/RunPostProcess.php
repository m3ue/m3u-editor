<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Facades\PlaylistUrlFacade;
use App\Models\Epg;
use App\Models\PostProcess;
use App\Models\PostProcessLog;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymphonyProcess;

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
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $modelType = get_class($this->model);
        $name = $this->model->name;
        $user = $this->model->user;
        $status = $this->model->status;
        $postProcess = $this->postProcess;
        $metadata = $postProcess->metadata;
        $sendFailed = ((bool)$metadata['send_failed']) ?? false;
        if ($status === Status::Failed && !$sendFailed) {
            // If the model status is failed and we don't want to execute the post process, then just return
            return;
        }
        try {
            // See if calling webhook, or running a script
            // If the metadata is a URL, then we're calling a webhook
            if (str_starts_with($metadata['path'], 'http')) {
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
                        $value = $this->model->{$var['value']};
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
                        ->broadcast($user);
                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($body)
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
                        ->broadcast($user);
                    Notification::make()
                        ->danger()
                        ->title($title)
                        ->body($body)
                        ->sendToDatabase($user);
                }
            } else {
                // If the metadata is not a URL, then we're running a script
                $cmd = $metadata['path'];
                $process = SymphonyProcess::fromShellCommandline($cmd);
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
                        $value = $this->model->{$var['value']};
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
                        if ($type === SymphonyProcess::OUT) {
                            $output .= $buffer;
                        }
                        if ($type === SymphonyProcess::ERR) {
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
                        ->broadcast($user);
                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($body)
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
                        ->broadcast($user);
                    Notification::make()
                        ->danger()
                        ->title($title)
                        ->body($body)
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
}
