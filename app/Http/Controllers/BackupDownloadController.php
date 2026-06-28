<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $disk, string $path): StreamedResponse
    {
        abort_unless($request->user()?->can('download-backup'), 403);
        abort_unless(in_array($disk, FilamentSpatieLaravelBackup::getDisks(), true), 404);

        $decodedPath = $this->decodePath($path);
        abort_if($decodedPath === null || ! Storage::disk($disk)->exists($decodedPath), 404);

        $stream = Storage::disk($disk)->readStream($decodedPath);
        abort_if($stream === false, 404);

        $contentType = str_ends_with($decodedPath, '.tar.gz') ? 'application/gzip' : 'application/zip';

        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) Storage::disk($disk)->size($decodedPath),
        ];

        return response()->streamDownload(function () use ($stream): void {
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, basename($decodedPath), $headers);
    }

    private function decodePath(string $path): ?string
    {
        $paddedPath = str_pad(strtr($path, '-_', '+/'), strlen($path) % 4 === 0 ? strlen($path) : strlen($path) + 4 - (strlen($path) % 4), '=', STR_PAD_RIGHT);
        $decodedPath = base64_decode($paddedPath, true);

        if ($decodedPath === false || $decodedPath === '' || str_contains($decodedPath, "\0") || str_contains($decodedPath, '..')) {
            return null;
        }

        return $decodedPath;
    }
}
