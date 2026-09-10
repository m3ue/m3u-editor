<?php

namespace App\Livewire\EasyEditor\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Drag-reorder that renders only one page at a time but still produces a
 * correct, clean global order.
 *
 * Filament's stock reorder loads *every* matching row when you enter reorder
 * mode (see HasRecords::getTableRecords) and renumbers the whole set 1..N. For
 * a group with a thousand-plus channels that renders thousands of interactive
 * cells at once and hangs / OOMs the browser tab. Pairing `->reorderable()`
 * with `->paginatedWhileReordering()` keeps only the current page in the DOM,
 * but stock `reorderTable()` would then renumber just that page to 1..N,
 * colliding with every other page's sort values.
 *
 * This override instead:
 *  1. Reads the full group's current key order (by the reorder column, so it
 *     matches whatever's already on screen across all pages).
 *  2. Splices the dragged page's keys back into their original slot
 *     positions, in their new visual order.
 *  3. Writes a clean 1..N sequence over the *whole* group in a single
 *     `UPDATE ... CASE` statement (built with Filament's own, driver-escaped
 *     CASE-expression helper - see makeTableReorderColumnExpression()).
 *
 * Step 3 also fixes rows that all share the same sort value (e.g. every
 * channel imported with a playlist's auto-sort off, which leaves `sort = 0`
 * on all of them - see ProcessM3uImport). Permuting identical values is a
 * no-op that silently reverts on refresh; assigning a fresh sequence never is.
 *
 * Steps 1 and 3 touch every row in the group, not just the visible page, so
 * a drag on a multi-thousand-channel group issues one query plucking every
 * key and one UPDATE with a same-sized CASE expression. This is intentional:
 * it's ID-only (no model hydration, so PHP memory stays flat regardless of
 * group size) and a single statement is still far cheaper than the N
 * round-trips a chunked-write alternative would cost, for group sizes this
 * app actually sees. Revisit only if a driver's statement-size limit becomes
 * a real constraint - not before.
 */
trait ReordersVisiblePage
{
    /**
     * @param  array<int|string>  $order  Record keys in their new visual order (one page's worth).
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $orderColumn = (string) str($this->getTable()->getReorderColumn())->afterLast('.');
        $dragged = array_values($order);

        if ($dragged === []) {
            return;
        }

        $this->getTable()->callBeforeReordering($order);

        DB::transaction(function () use ($dragged, $orderColumn): void {
            $model = app($this->getTable()->getModel());
            $keyName = $model->getKeyName();

            // Every key in the group (not just this page), in its current order.
            $allKeys = (clone $this->getTable()->getQuery())
                ->reorder()
                ->orderBy($orderColumn)
                ->orderBy($keyName)
                ->pluck($keyName)
                ->all();

            // Replace the dragged keys' original positions with their new
            // relative order; everything else keeps its position.
            $draggedPositions = array_flip($dragged);
            $cursor = 0;
            foreach ($allKeys as $index => $key) {
                if (isset($draggedPositions[$key])) {
                    $allKeys[$index] = $dragged[$cursor++];
                }
            }

            if ($allKeys === []) {
                return;
            }

            $connection = $model->getConnection();

            // Reuse Filament's own CASE-expression builder (CanReorderRecords,
            // pulled in via InteractsWithTable) rather than hand-rolling raw SQL:
            // it already wraps the column and escapes each key through the
            // connection driver.
            (clone $this->getTable()->getQuery())
                ->whereIn($keyName, $allKeys)
                ->update([
                    $orderColumn => $this->makeTableReorderColumnExpression(
                        $allKeys,
                        $connection->getQueryGrammar()->wrap($keyName),
                        $connection,
                    ),
                ]);
        });

        $this->getTable()->callAfterReordering($order);
    }
}
