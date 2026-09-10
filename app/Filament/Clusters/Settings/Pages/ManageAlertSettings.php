<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Notifications\TelegramAlert;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\HtmlString;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Spatie\SlackAlerts\Facades\SlackAlert;

class ManageAlertSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $slug = 'alerts';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return __('Alerts');
    }

    public function getTitle(): string
    {
        return __('Alerts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->contained(false)
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Discord'))
                            ->id('discord')
                            ->icon('heroicon-m-chat-bubble-left-right')
                            ->schema([
                                Section::make(__('Discord'))
                                    ->description(__('Send alerts to a Discord channel via an incoming webhook.'))
                                    ->headerActions([
                                        Action::make('test_discord_alert')
                                            ->label(__('Send test alert'))
                                            ->icon('heroicon-o-paper-airplane')
                                            ->color('gray')
                                            ->size('sm')
                                            ->visible(fn (Get $get): bool => (bool) $get('discord_alerts_enabled') && ! empty($get('discord_webhook_url')))
                                            ->action(function (Get $get): void {
                                                $webhookUrl = $get('discord_webhook_url');

                                                if (empty($webhookUrl)) {
                                                    Notification::make()
                                                        ->title(__('No Webhook URL'))
                                                        ->body(__('Please enter a Discord webhook URL first.'))
                                                        ->warning()
                                                        ->send();

                                                    return;
                                                }

                                                try {
                                                    DiscordAlert::to($webhookUrl)->message('[TEST] This is a test alert from m3u-editor. Your Discord integration is working correctly.');

                                                    Notification::make()
                                                        ->title(__('Test Alert Sent'))
                                                        ->body(__('Check your Discord channel for the test message.'))
                                                        ->success()
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->title(__('Failed to Send Alert'))
                                                        ->body($e->getMessage())
                                                        ->danger()
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        Toggle::make('discord_alerts_enabled')
                                            ->label(__('Enable Discord alerts'))
                                            ->helperText(__('When enabled, error-level log entries will be forwarded to your Discord channel.'))
                                            ->live(),
                                        TextInput::make('discord_webhook_url')
                                            ->label(__('Discord Webhook URL'))
                                            ->url()
                                            ->placeholder(__('https://discord.com/api/webhooks/...'))
                                            ->helperText(__('Create an Incoming Webhook in your Discord server settings and paste the URL here.'))
                                            ->visible(fn (Get $get): bool => (bool) $get('discord_alerts_enabled'))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('Slack'))
                            ->id('slack')
                            ->icon('heroicon-m-hashtag')
                            ->schema([
                                Section::make(__('Slack'))
                                    ->description(__('Send alerts to a Slack channel via an incoming webhook.'))
                                    ->headerActions([
                                        Action::make('test_slack_alert')
                                            ->label(__('Send test alert'))
                                            ->icon('heroicon-o-paper-airplane')
                                            ->color('gray')
                                            ->size('sm')
                                            ->visible(fn (Get $get): bool => (bool) $get('slack_alerts_enabled') && ! empty($get('slack_webhook_url')))
                                            ->action(function (Get $get): void {
                                                $webhookUrl = $get('slack_webhook_url');

                                                if (empty($webhookUrl)) {
                                                    Notification::make()
                                                        ->title(__('No Webhook URL'))
                                                        ->body(__('Please enter a Slack webhook URL first.'))
                                                        ->warning()
                                                        ->send();

                                                    return;
                                                }

                                                try {
                                                    SlackAlert::to($webhookUrl)->sync()->message('[TEST] This is a test alert from m3u-editor. Your Slack integration is working correctly.');

                                                    Notification::make()
                                                        ->title(__('Test Alert Sent'))
                                                        ->body(__('Check your Slack channel for the test message.'))
                                                        ->success()
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->title(__('Failed to Send Alert'))
                                                        ->body($e->getMessage())
                                                        ->danger()
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        Toggle::make('slack_alerts_enabled')
                                            ->label(__('Enable Slack alerts'))
                                            ->helperText(__('When enabled, error-level log entries will be forwarded to your Slack channel.'))
                                            ->live(),
                                        Callout::make(__('Setup Guide'))
                                            ->description(new HtmlString(<<<'HTML'
<div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
    <p>Create a Slack App using the manifest below, then paste the generated webhook URL into the field below.</p>
    <ol class="list-decimal list-inside space-y-1.5 ml-1">
        <li>Go to <a href="https://api.slack.com/apps" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline font-medium">api.slack.com/apps</a> and click <strong class="text-gray-700 dark:text-gray-300">Create New App</strong></li>
        <li>Choose <strong class="text-gray-700 dark:text-gray-300">From an app manifest</strong></li>
        <li>Select your workspace and click <strong class="text-gray-700 dark:text-gray-300">Next</strong></li>
        <li>Switch to the <strong class="text-gray-700 dark:text-gray-300">JSON</strong> tab, paste the manifest below, then click <strong class="text-gray-700 dark:text-gray-300">Next &rarr; Create</strong></li>
        <li>In the app settings, go to <strong class="text-gray-700 dark:text-gray-300">Incoming Webhooks</strong> and toggle it <strong class="text-gray-700 dark:text-gray-300">On</strong></li>
        <li>Click <strong class="text-gray-700 dark:text-gray-300">Add New Webhook to Workspace</strong>, select a channel, then click <strong class="text-gray-700 dark:text-gray-300">Allow</strong></li>
        <li>Copy the <strong class="text-gray-700 dark:text-gray-300">Webhook URL</strong> from the list and paste it into the field below</li>
        <li><em>Optional:</em> To add the m3u editor icon go to <strong class="text-gray-700 dark:text-gray-300">Basic Information &rarr; Display Information</strong> and upload the icon from the URL at the bottom of this guide</li>
    </ol>
    <div class="mt-3">
        <p class="font-medium text-gray-700 dark:text-gray-300 mb-1.5">App Manifest (JSON):</p>
        <pre class="bg-gray-100 dark:bg-gray-800 rounded-lg p-3 text-xs overflow-x-auto text-gray-700 dark:text-gray-300 select-all">{
    "display_information": {
        "name": "m3u editor",
        "description": "Alerts and notifications from m3u editor",
        "background_color": "#000000"
    },
    "features": {
        "bot_user": {
            "display_name": "m3u editor",
            "always_online": false
        }
    },
    "oauth_config": {
        "scopes": {
            "bot": [
                "incoming-webhook"
            ]
        }
    },
    "settings": {
        "org_deploy_enabled": false,
        "socket_mode_enabled": false,
        "is_hosted": false,
        "token_rotation_enabled": false
    }
}</pre>
    </div>
    <div class="mt-2">
        <p class="font-medium text-gray-700 dark:text-gray-300 mb-1">Optional App Icon URL:</p>
        <code class="bg-gray-100 dark:bg-gray-800 rounded px-2 py-1 text-xs text-gray-700 dark:text-gray-300 select-all">https://raw.githubusercontent.com/m3ue/m3u-editor/refs/heads/master/public/logo.png</code>
    </div>
</div>
HTML))
                                            ->visible(fn (Get $get): bool => (bool) $get('slack_alerts_enabled'))
                                            ->columnSpanFull(),
                                        TextInput::make('slack_webhook_url')
                                            ->label(__('Slack Webhook URL'))
                                            ->url()
                                            ->hintAction(
                                                Action::make('get_slack_webhook_url')
                                                    ->label(__('Open Slack Apps'))
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->iconPosition('after')
                                                    ->size('sm')
                                                    ->url('https://api.slack.com/apps')
                                                    ->openUrlInNewTab(true)
                                            )
                                            ->placeholder(__('https://hooks.slack.com/services/...'))
                                            ->helperText(__('Follow the setup guide above to create a Slack App and generate a webhook URL.'))
                                            ->visible(fn (Get $get): bool => (bool) $get('slack_alerts_enabled'))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('Telegram'))
                            ->id('telegram')
                            ->icon('heroicon-m-paper-airplane')
                            ->schema([
                                Section::make(__('Telegram'))
                                    ->description(__('Send alerts to a Telegram chat via a bot.'))
                                    ->headerActions([
                                        Action::make('test_telegram_alert')
                                            ->label(__('Send test alert'))
                                            ->icon('heroicon-o-paper-airplane')
                                            ->color('gray')
                                            ->size('sm')
                                            ->visible(fn (Get $get): bool => (bool) $get('telegram_alerts_enabled') && ! empty($get('telegram_bot_token')) && ! empty($get('telegram_chat_id')))
                                            ->action(function (Get $get): void {
                                                $botToken = $get('telegram_bot_token');
                                                $chatId = $get('telegram_chat_id');

                                                if (empty($botToken) || empty($chatId)) {
                                                    Notification::make()
                                                        ->title(__('Missing Bot Token or Chat ID'))
                                                        ->body(__('Please enter a Telegram bot token and chat ID first.'))
                                                        ->warning()
                                                        ->send();

                                                    return;
                                                }

                                                try {
                                                    NotificationFacade::route('telegram', $chatId)
                                                        ->notifyNow(new TelegramAlert('[TEST] This is a test alert from m3u-editor. Your Telegram integration is working correctly.', Crypt::encryptString($botToken)));

                                                    Notification::make()
                                                        ->title(__('Test Alert Sent'))
                                                        ->body(__('Check your Telegram chat for the test message.'))
                                                        ->success()
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->title(__('Failed to Send Alert'))
                                                        ->body(__('Could not send the test alert. Check your bot token and chat ID and try again.'))
                                                        ->danger()
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        Toggle::make('telegram_alerts_enabled')
                                            ->label(__('Enable Telegram alerts'))
                                            ->helperText(__('When enabled, error-level log entries will be forwarded to your Telegram chat.'))
                                            ->live(),
                                        Callout::make(__('Setup Guide'))
                                            ->description(new HtmlString(<<<'HTML'
<div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
    <p>Create a Telegram bot and find the chat ID to send alerts to.</p>
    <ol class="list-decimal list-inside space-y-1.5 ml-1">
        <li>Open Telegram and start a chat with <a href="https://t.me/BotFather" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline font-medium">@BotFather</a></li>
        <li>Send <strong class="text-gray-700 dark:text-gray-300">/newbot</strong> and follow the prompts to name your bot</li>
        <li>Copy the <strong class="text-gray-700 dark:text-gray-300">bot token</strong> BotFather gives you and paste it below</li>
        <li>Start a chat with your new bot and send it any message (for group alerts, add the bot to the group and post a message there)</li>
        <li>Open <code class="bg-gray-100 dark:bg-gray-800 rounded px-1.5 py-0.5 text-xs text-gray-700 dark:text-gray-300">https://api.telegram.org/bot&lt;YOUR_BOT_TOKEN&gt;/getUpdates</code> in your browser</li>
        <li>Find <strong class="text-gray-700 dark:text-gray-300">"chat":{"id":...}</strong> in the response and paste that ID below (group IDs are negative numbers)</li>
    </ol>
</div>
HTML))
                                            ->visible(fn (Get $get): bool => (bool) $get('telegram_alerts_enabled'))
                                            ->columnSpanFull(),
                                        TextInput::make('telegram_bot_token')
                                            ->label(__('Telegram Bot Token'))
                                            ->password()
                                            ->revealable()
                                            ->placeholder(__('123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11'))
                                            ->helperText(__('The bot token you received from BotFather.'))
                                            ->visible(fn (Get $get): bool => (bool) $get('telegram_alerts_enabled'))
                                            ->columnSpanFull(),
                                        TextInput::make('telegram_chat_id')
                                            ->label(__('Telegram Chat ID'))
                                            ->placeholder(__('e.g. 123456789 or -100123456789'))
                                            ->helperText(__('The ID of the chat, group or channel to send alerts to.'))
                                            ->visible(fn (Get $get): bool => (bool) $get('telegram_alerts_enabled'))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('Additional Notifications'))
                            ->id('additional')
                            ->icon('heroicon-m-bell')
                            ->visible(fn (Get $get): bool => (bool) $get('discord_alerts_enabled') || (bool) $get('slack_alerts_enabled') || (bool) $get('telegram_alerts_enabled'))
                            ->schema([
                                Section::make(__('Additional Notifications'))
                                    ->description(__('Opt in to targeted notifications beyond the default error log forwarding.'))
                                    ->schema([
                                        Toggle::make('alerts_on_job_failed')
                                            ->label(__('Notify on queued job failures'))
                                            ->helperText(__('Sends an alert whenever a queued job (import, sync, probe, etc.) fails permanently after all retry attempts.')),
                                        Toggle::make('alerts_on_import_failed')
                                            ->label(__('Notify on playlist import failures'))
                                            ->helperText(__('Sends an alert when a playlist sync fails entirely, e.g. all provider URLs were unreachable.')),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
