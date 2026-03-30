<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogViewer extends Page
{
    protected static ?string $navigationLabel = 'Debug Logs';

    protected static ?string $title = 'Debug Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = null;

    public static function canAccess(): bool
    {
        return auth()->check()
            && auth()->user()->isAdmin();
    }

    public function getView(): string
    {
        return 'filament.pages.log-viewer';
    }

    // ── State ────────────────────────────────────────────────────────────────

    public string $selectedFile = '';

    public string $levelFilter = 'all';

    public string $search = '';

    public int $perPage = 50;

    public int $page = 1;

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $files = $this->getLogFiles();

        if (! empty($files)) {
            $this->selectedFile = array_key_first($files);
        }
    }

    // ── Computed helpers ─────────────────────────────────────────────────────

    /** Returns [ relative-name => absolute-path ] sorted newest-first. */
    public function getLogFiles(): array
    {
        $dir = config('app.log.dir', storage_path('logs'));

        if (! is_dir($dir)) {
            return [];
        }

        $files = collect(File::allFiles($dir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'laravel-') && str_ends_with($f->getFilename(), '.log'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->mapWithKeys(fn ($f) => [
                $f->getFilename() => $f->getRealPath(),
            ])
            ->all();

        return $files;
    }

    /** Returns parsed entries, filtered & paginated. */
    public function getParsedEntries(): array
    {
        $files = $this->getLogFiles();
        $path = $files[$this->selectedFile] ?? null;

        if (! $path || ! file_exists($path)) {
            return ['entries' => [], 'total' => 0, 'pages' => 0];
        }

        $entries = $this->parseLogFile($path);

        // Level filter
        if ($this->levelFilter !== 'all') {
            $entries = array_filter(
                $entries,
                fn ($e) => strtolower($e['level']) === strtolower($this->levelFilter)
            );
        }

        // Text search
        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $entries = array_filter(
                $entries,
                fn ($e) => str_contains(strtolower($e['message']), $needle)
                    || str_contains(strtolower($e['context'] ?? ''), $needle)
                    || str_contains(strtolower($e['stack'] ?? ''), $needle)
            );
        }

        $entries = array_values($entries);
        $total = count($entries);
        $pages = (int) ceil($total / max($this->perPage, 1));

        $offset = ($this->page - 1) * $this->perPage;
        $entries = array_slice($entries, $offset, $this->perPage);

        return ['entries' => $entries, 'total' => $total, 'pages' => $pages];
    }

    /** Parse a laravel daily log file into structured entries. */
    protected function parseLogFile(string $path): array
    {
        // Read last 2 MB to keep memory reasonable for huge files
        $maxBytes = 2 * 1024 * 1024;
        $size = filesize($path);
        $content = '';

        if ($size <= $maxBytes) {
            $content = file_get_contents($path);
        } else {
            $fh = fopen($path, 'rb');
            fseek($fh, -$maxBytes, SEEK_END);
            $content = fread($fh, $maxBytes);
            fclose($fh);
            // Drop the first (incomplete) line
            $content = substr($content, strpos($content, "\n") + 1);
        }

        // Split on log entry header: [YYYY-MM-DD HH:MM:SS]
        $pattern = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\S+?)\.(\w+): /m';

        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $entries = [];

        // parts: [pre, date, env, level, body, date, env, level, body, …]
        $i = 1; // skip leading text before first entry
        while ($i < count($parts) - 3) {
            $date = $parts[$i];
            $env = $parts[$i + 1];
            $level = $parts[$i + 2];
            $body = trim($parts[$i + 3] ?? '');

            // Split body into message line + stack
            $newline = strpos($body, "\n");
            if ($newline === false) {
                $messageLine = $body;
                $stack = '';
            } else {
                $messageLine = substr($body, 0, $newline);
                $stack = trim(substr($body, $newline + 1));
            }

            // Separate JSON context from end of message line
            $context = '';
            if (preg_match('/^(.*?)(\{.*\}|\[.*\])$/s', $messageLine, $m)) {
                $messageLine = rtrim($m[1]);
                $context = $m[2];
            }

            $entries[] = [
                'date' => $date,
                'env' => $env,
                'level' => strtoupper($level),
                'message' => $messageLine,
                'context' => $context,
                'stack' => $stack,
            ];

            $i += 4;
        }

        // Newest first
        return array_reverse($entries);
    }

    // ── Livewire updaters ────────────────────────────────────────────────────

    public function updatedSelectedFile(): void
    {
        $this->page = 1;
    }

    public function updatedLevelFilter(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
    }

    public function nextPage(int $max): void
    {
        if ($this->page < $max) {
            $this->page++;
        }
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    // ── Header actions ───────────────────────────────────────────────────────

    public function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadLog()),

            Action::make('clear')
                ->label('Clear log')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear log file?')
                ->modalDescription('This will permanently delete all entries in the selected log file. This cannot be undone.')
                ->modalSubmitActionLabel('Clear')
                ->action(fn () => $this->clearLog()),
        ];
    }

    public function downloadLog(): StreamedResponse
    {
        $files = $this->getLogFiles();
        $path = $files[$this->selectedFile] ?? null;

        abort_unless($path && file_exists($path), 404);

        return response()->streamDownload(
            fn () => print file_get_contents($path),
            $this->selectedFile
        );
    }

    public function clearLog(): void
    {
        $files = $this->getLogFiles();
        $path = $files[$this->selectedFile] ?? null;

        if (! $path || ! file_exists($path)) {
            return;
        }

        file_put_contents($path, '');
        $this->page = 1;

        Notification::make()
            ->title('Log cleared')
            ->success()
            ->send();
    }
}
