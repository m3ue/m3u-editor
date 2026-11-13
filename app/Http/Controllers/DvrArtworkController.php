<?php

namespace App\Http\Controllers;

use App\Services\DvrArtworkService;
use App\Services\LogoCacheService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrArtworkController extends Controller
{
    /**
     * Serve the generated poster or backdrop for a DVR recording that no
     * metadata provider matched. The URL is signed when the recording is added
     * to the library, so only art the server handed out can be rendered.
     */
    public function __invoke(DvrArtworkService $artwork, string $kind, string $logo): StreamedResponse
    {
        $logoUrl = DvrArtworkService::decodeLogoParameter($logo);
        abort_if($logoUrl === null, 404);

        return LogoCacheService::streamResponse($artwork->cachedFile($kind, $logoUrl), 'image/jpeg');
    }
}
