<?php

namespace App\Http\Controllers;

use App\Models\DvrRecording;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrStreamController extends Controller
{
    /**
     * Stream a completed DVR recording file to the client.
     *
     * GET /dvr/recordings/{uuid}/stream
     */
    public function stream(Request $request, string $uuid): Response|StreamedResponse
    {
        $recording = DvrRecording::where('uuid', $uuid)->firstOrFail();

        if (! $recording->hasFile()) {
            abort(404, 'Recording file not available');
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            abort(404, 'DVR setting not found');
        }

        $disk = $setting->storage_disk ?: config('dvr.storage_disk');

        if (! Storage::disk($disk)->exists($recording->file_path)) {
            abort(404, 'Recording file not found on disk');
        }

        $fullPath = Storage::disk($disk)->path($recording->file_path);
        $fileSize = filesize($fullPath);
        $mimeType = 'video/mp2t';

        // Support range requests for seeking
        $range = $request->header('Range');

        if ($range) {
            preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
            $start = (int) $matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
            $length = $end - $start + 1;

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.basename($recording->file_path).'"',
            ];

            return response()->stream(function () use ($fullPath, $start, $length) {
                $handle = fopen($fullPath, 'rb');
                fseek($handle, $start);
                $remaining = $length;

                while (! feof($handle) && $remaining > 0) {
                    $chunkSize = min(8192, $remaining);
                    echo fread($handle, $chunkSize);
                    $remaining -= $chunkSize;
                    flush();
                }

                fclose($handle);
            }, 206, $headers);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.basename($recording->file_path).'"',
        ];

        return response()->stream(function () use ($fullPath) {
            $handle = fopen($fullPath, 'rb');

            while (! feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }

            fclose($handle);
        }, 200, $headers);
    }
}
