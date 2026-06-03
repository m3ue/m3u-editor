<?php

namespace App\Console\Commands;

use App\Services\M3uProxyService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterM3uProxyWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u-proxy:register-webhook 
                            {--force : Force re-registration even if webhook already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register m3u-editor webhook with m3u-proxy for real-time cache invalidation';

    /**
     * Execute the console command.
     */
    public function handle(M3uProxyService $service): int
    {
        $this->info('🔗 Checking m3u-editor webhook status with m3u-proxy...');

        // Construct webhook URL - use APP_URL instead of apiPublicUrl
        // because m3u-proxy needs to call back to Laravel, not to itself
        $webhookUrl = $service->getWebhookUrl();

        if (! $webhookUrl) {
            $this->info('ℹ️  Proxy resolver URL is not configured. Skipping webhook registration.');

            return self::SUCCESS;
        }

        $this->info("Webhook URL: {$webhookUrl}");

        try {
            $apiBaseUrl = $service->getApiBaseUrl();
            $webhooksEndpoint = $apiBaseUrl.'/webhooks';

            // Check if webhook already exists
            $listResponse = $this->buildApiRequest($service, 5)->get($webhooksEndpoint);

            if ($listResponse->successful()) {
                $webhooks = $listResponse->json('webhooks', []);
                $alreadyRegistered = collect($webhooks)->contains('url', $webhookUrl);

                if ($alreadyRegistered && ! $this->option('force')) {
                    $this->info('✅ Webhook already registered');

                    return self::SUCCESS;
                }

                if ($alreadyRegistered) {
                    $this->warn('⚠️  Webhook already registered, removing and re-registering...');

                    $deleteResponse = $this->buildApiRequest($service, 5)->delete($webhooksEndpoint, [
                        'webhook_url' => $webhookUrl,
                    ]);

                    if (! $deleteResponse->successful()) {
                        $this->warn('⚠️  Failed to remove existing webhook, continuing anyway...');
                    }
                }
            }

            // Register webhook
            $payload = [
                'url' => $webhookUrl,
                'events' => [
                    'client_connected',
                    'client_disconnected',
                    'stream_started',
                    'stream_stopped',
                ],
                'timeout' => 5,
                'retry_attempts' => 2,
            ];

            $this->info('Registering webhook with events: '.implode(', ', $payload['events']));

            $response = $this->buildApiRequest($service, 10)->post($webhooksEndpoint, $payload);

            if ($response->successful()) {
                $this->info('✅ Webhook registered successfully!');

                Log::info('M3U Proxy webhook registered', [
                    'webhook_url' => $webhookUrl,
                    'events' => $payload['events'],
                ]);

                return self::SUCCESS;
            }

            $this->error('❌ Failed to register webhook: '.$response->body());

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ Error registering webhook: '.$e->getMessage());
            Log::error('Failed to register m3u-proxy webhook', [
                'error' => $e->getMessage(),
                'webhook_url' => $webhookUrl,
            ]);

            return self::FAILURE;
        }
    }

    private function buildApiRequest(M3uProxyService $service, int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout)->acceptJson();

        if ($token = $service->getApiToken()) {
            $request = $request->withHeaders(['X-API-Token' => $token]);
        }

        return $request;
    }
}
