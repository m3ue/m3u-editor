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

        return response()->file(
            $disk->path($asset->path),
            [
                'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }
}
