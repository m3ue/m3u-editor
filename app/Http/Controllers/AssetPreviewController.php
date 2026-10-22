<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetPreviewController extends Controller
{
    public function __invoke(Asset $asset): BinaryFileResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->isAdmin() || $user->canUseTools()), 403);

        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->path), 404);

        // Re-sniff storage content; inventory metadata can be stale or incorrect.
        $inlineMimeType = $asset->inlinePreviewMimeType();
        $headers = [
            'Content-Type' => $inlineMimeType ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if ($inlineMimeType === null) {
            return response()->download($disk->path($asset->path), $asset->name, $headers)->setPrivate();
        }

        return response()->file($disk->path($asset->path), $headers)
            ->setPrivate()
            ->setContentDisposition('inline', $asset->name);
    }
}
