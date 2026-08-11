<?php

namespace App\Http\Controllers;

use App\Models\DvrRecording;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrRecordingDownloadController extends Controller
{
    /**
     * Download a DVR recording file as a real HTTP GET, outside the Livewire
     * request/response cycle. Filament Action closures that return a
     * StreamedResponse get buffered whole into memory, base64-encoded, and
     * shipped as one JSON payload by Livewire's SupportFileDownloads hook -
     * fine for small exports, but it means the entire recording file (which
     * can be several GB) sits in PHP memory and then browser JS memory
     * before it's saved. Routing through a plain controller avoids that
     * entirely: the browser performs a normal navigation/download and the
     * response streams straight through.
     */
    public function __invoke(Request $request, DvrRecording $recording): StreamedResponse
    {
        abort_unless($recording->user_id === $request->user()->id, 403);
        abort_unless($recording->hasFilePath(), 404);

        $response = $recording->downloadResponse();

        abort_if($response === null, 404, 'Recording file not found on disk.');

        return $response;
    }
}
