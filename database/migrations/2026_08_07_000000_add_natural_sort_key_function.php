<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a `m3u_natural_sort_key(text)` SQL function used by SortService to sort
 * channel/series titles naturally (e.g. "Channel 2" before "Channel 10")
 * without pulling rows into PHP — the ROW_NUMBER()-based sort stays a single,
 * database-side query. It lower-cases the input and zero-pads every run of
 * digits to a fixed width, so a plain string comparison of the result sorts
 * numeric runs numerically instead of lexicographically.
 *
 * SQLite has no persistent user-defined functions (no server process to hold
 * one), so it isn't handled here — SortService registers an equivalent
 * function on the PDO connection at runtime via sqliteCreateFunction(),
 * backed by the same padding algorithm (SortService::naturalSortKey()).
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::unprepared(<<<'SQL'
                CREATE FUNCTION m3u_natural_sort_key(input TEXT) RETURNS TEXT DETERMINISTIC
                BEGIN
                    DECLARE result TEXT DEFAULT '';
                    DECLARE digits TEXT DEFAULT '';
                    DECLARE i INT DEFAULT 1;
                    DECLARE len INT;
                    DECLARE ch CHAR(1);
                    DECLARE lowered TEXT;

                    IF input IS NULL THEN
                        RETURN NULL;
                    END IF;

                    SET lowered = LOWER(input);
                    SET len = CHAR_LENGTH(lowered);

                    WHILE i <= len DO
                        SET ch = SUBSTRING(lowered, i, 1);
                        IF ch REGEXP '^[0-9]$' THEN
                            SET digits = CONCAT(digits, ch);
                        ELSE
                            IF digits <> '' THEN
                                SET result = CONCAT(result, LPAD(digits, 12, '0'));
                                SET digits = '';
                            END IF;
                            SET result = CONCAT(result, ch);
                        END IF;
                        SET i = i + 1;
                    END WHILE;

                    IF digits <> '' THEN
                        SET result = CONCAT(result, LPAD(digits, 12, '0'));
                    END IF;

                    RETURN result;
                END
                SQL),
            'pgsql' => DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION m3u_natural_sort_key(input TEXT) RETURNS TEXT AS $func$
                DECLARE
                    result TEXT := '';
                    digits TEXT := '';
                    ch TEXT;
                    lowered TEXT;
                    i INT;
                    len INT;
                BEGIN
                    IF input IS NULL THEN
                        RETURN NULL;
                    END IF;

                    lowered := lower(input);
                    len := char_length(lowered);
                    i := 1;
                    WHILE i <= len LOOP
                        ch := substr(lowered, i, 1);
                        IF ch ~ '^[0-9]$' THEN
                            digits := digits || ch;
                        ELSE
                            IF digits <> '' THEN
                                result := result || lpad(digits, 12, '0');
                                digits := '';
                            END IF;
                            result := result || ch;
                        END IF;
                        i := i + 1;
                    END LOOP;

                    IF digits <> '' THEN
                        result := result || lpad(digits, 12, '0');
                    END IF;

                    RETURN result;
                END;
                $func$ LANGUAGE plpgsql IMMUTABLE;
                SQL),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::unprepared('DROP FUNCTION IF EXISTS m3u_natural_sort_key'),
            'pgsql' => DB::unprepared('DROP FUNCTION IF EXISTS m3u_natural_sort_key(TEXT)'),
            default => null,
        };
    }
};
