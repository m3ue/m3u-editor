<?php

namespace App\Http\Middleware;

use App\Models\TableColumnPreference;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges Filament's session-only table column manager (order + visibility)
 * to the database, so preferences follow the user across devices/browsers
 * instead of resetting on every new session. See
 * https://github.com/m3ue/m3u-editor/issues/1348.
 *
 * Filament (Filament\Tables\Concerns\HasColumnManager) stores column state
 * under session keys shaped like "tables.{md5(pageClass)}_columns" and
 * "tables.{md5(pageClass)}_has_reordered_columns". That hash is derived only
 * from the page/relation-manager class name, so it's identical for a given
 * table across every device and login for a user — it is NOT a per-browser
 * session identifier, which is what makes bridging it through the database
 * safe: two different browsers resolve to the same key and therefore the
 * same saved preference.
 *
 * Flow:
 *  1. Request starts (handle()): seed any DB-stored column-manager keys into
 *     the current session, but only where the session doesn't already have
 *     a value — this runs before Filament mounts the page and reads them.
 *  2. Filament reads/writes those session keys as normal during the request.
 *  3. Response is sent (terminate()): re-read the session's column-manager
 *     keys and persist anything Filament just wrote, per user.
 *
 * No per-resource or per-page code is required for this to apply — it works
 * for every current and future Filament table in the panel automatically.
 */
class SyncTableColumnPreferences
{
    private const TABLES_SESSION_KEY = 'tables';

    private const KEY_PATTERN = '/^[a-f0-9]{32}_(columns|has_reordered_columns)$/';

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->hasSession() && auth()->check()) {
            $session = $request->session();
            $sessionTables = $session->get(self::TABLES_SESSION_KEY, []);

            TableColumnPreference::query()
                ->where('user_id', auth()->id())
                ->get(['table_key', 'value'])
                ->each(function (TableColumnPreference $preference) use (&$sessionTables): void {
                    if (! array_key_exists($preference->table_key, $sessionTables) && preg_match(self::KEY_PATTERN, $preference->table_key)) {
                        $sessionTables[$preference->table_key] = $preference->value;
                    }
                });

            $session->put(self::TABLES_SESSION_KEY, $sessionTables);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->hasSession() || ! auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $sessionTables = $request->session()->get(self::TABLES_SESSION_KEY, []);

        collect($sessionTables)
            ->filter(fn ($value, string $key) => preg_match(self::KEY_PATTERN, $key) === 1)
            ->each(fn ($value, string $key) => TableColumnPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'table_key' => $key],
                ['value' => $value],
            ));
    }
}
