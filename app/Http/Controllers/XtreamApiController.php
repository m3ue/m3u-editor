<?php

namespace App\Http\Controllers;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User; // Assuming Playlist model has a user relationship
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;

class XtreamApiController extends Controller
{
    public function handle(Request $request, string $uuid)
    {
        $playlist = null;
        $playlistModelType = null;

        try {
            $playlist = Playlist::with([
                'playlistAuth', 'user',
                'channels' => fn($q) => $q->where('enabled', true)->with('group'),
                'series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])
            ])->findOrFail($uuid);
            $playlistModelType = 'Playlist';
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with([
                    'playlistAuth', 'user',
                    'channels' => fn($q) => $q->where('enabled', true)->with('group')
                ])->findOrFail($uuid);
                $playlistModelType = 'MergedPlaylist';
                if (method_exists($playlist, 'series')) {
                    $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with([
                        'playlistAuth', 'user',
                        'channels' => fn($q) => $q->where('enabled', true)->with('group')
                    ])->findOrFail($uuid);
                    $playlistModelType = 'CustomPlaylist';
                    // Custom playlists might not have series, or if they do, ensure category is loaded if applicable
                    if (method_exists($playlist, 'series')) {
                         $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                    }
                } catch (ModelNotFoundException $e) {
                    return response()->json(['error' => 'Playlist not found'], 404);
                }
            }
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $authenticated = false;

        if (empty($username) || empty($password)) {
            return response()->json(['error' => 'Unauthorized - Missing credentials'], 401);
        }

        if ($playlist->playlistAuth && $playlist->playlistAuth->is_enabled) {
            if ($playlist->playlistAuth->username === $username && $playlist->playlistAuth->password === $password) {
                $authenticated = true;
            }
        }

        if (!$authenticated && $username === 'm3ue') {
            if ($playlist->user && Hash::check($password, $playlist->user->password)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $action = $request->input('action', 'panel');

        if ($action === 'panel' || empty($request->input('action'))) {
            $userInfo = [
                'username' => $username,
                'password' => $password,
                'message' => '',
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => (string)Carbon::now()->addYears(10)->timestamp,
                'is_trial' => '0',
                'active_cons' => 1,
                'created_at' => (string)($playlist->user ? $playlist->user->created_at->timestamp : Carbon::now()->timestamp),
                'max_connections' => (string)($playlist->streams ?? 1),
                'allowed_output_formats' => ['m3u8', 'ts'],
            ];

            $responseCategories = [];
            $categoryMap = []; // To keep track of added category IDs to ensure uniqueness

            // Live Categories from Channel Groups
            if ($playlist->channels) {
                $liveGroups = $playlist->channels->pluck('group')->filter()->unique('id');
                foreach ($liveGroups as $group) {
                    if ($group && !isset($categoryMap[$group->id])) {
                        $responseCategories[] = [
                            'category_id' => (string)$group->id,
                            'category_name' => $group->name,
                            'parent_id' => 0,
                        ];
                        $categoryMap[$group->id] = true;
                    }
                }
            }

            // VOD Categories from Series Categories
            // Ensure $playlist->series exists and is a collection
            $enabledSeries = $playlist->series ?? new Collection();
            if ($enabledSeries->isNotEmpty()) {
                 $vodCategories = $enabledSeries->pluck('category')->filter()->unique('id');
                 foreach ($vodCategories as $category) {
                     if ($category && !isset($categoryMap[$category->id])) {
                         $responseCategories[] = [
                             'category_id' => (string)$category->id,
                             'category_name' => $category->name,
                             'parent_id' => 0,
                         ];
                         $categoryMap[$category->id] = true;
                     }
                 }
            }

            // Add a default "All" category if no other categories exist, or as a standard practice
            if (empty($responseCategories)) {
                $responseCategories[] = [
                    'category_id' => 'all', // A common practice for Xtream
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }


            return response()->json([
                'user_info' => $userInfo,
                'server_info' => new \stdClass(),
                'available_channels' => [],
                'series' => [],
                'categories' => $responseCategories,
            ]);
        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
