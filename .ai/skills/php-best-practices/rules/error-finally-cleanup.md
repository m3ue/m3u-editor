---
title: Finally for Cleanup
impact: MEDIUM
impactDescription: Guarantees resource cleanup regardless of success or failure
tags: error-handling, finally, cleanup, resources, php8
---

# Finally for Cleanup

Use `finally` blocks to guarantee cleanup code runs whether an exception occurs or not.

## Bad Example

```php
<?php

declare(strict_types=1);

function processFile(string $path): array
{
    $handle = fopen($path, 'r');

    try {
        $data = parseContents($handle);
        fclose($handle); // Skipped if parseContents throws
        return $data;
    } catch (ParseException $e) {
        fclose($handle); // Duplicated cleanup
        throw $e;
    }
    // If an unexpected exception occurs, handle is never closed
}

// Lock without guaranteed release
function updateInventory(int $productId, int $quantity): void
{
    $lock = Cache::lock("product:{$productId}", 10);
    $lock->get();

    $product = Product::find($productId);
    $product->stock -= $quantity;
    $product->save();

    $lock->release(); // Never called if save() throws
}
```

## Good Example

```php
<?php

declare(strict_types=1);

function processFile(string $path): array
{
    $handle = fopen($path, 'r');

    try {
        return parseContents($handle);
    } finally {
        fclose($handle); // Always runs, even if exception thrown
    }
}

// Lock with guaranteed release
function updateInventory(int $productId, int $quantity): void
{
    $lock = Cache::lock("product:{$productId}", 10);
    $lock->get();

    try {
        $product = Product::find($productId);
        $product->stock -= $quantity;
        $product->save();
    } finally {
        $lock->release(); // Always released
    }
}

// Database transaction with guaranteed cleanup
function transferFunds(Account $from, Account $to, float $amount): void
{
    $pdo = getConnection();
    $pdo->beginTransaction();

    try {
        $from->debit($amount);
        $to->credit($amount);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Temporary state restoration
function withLocale(string $locale, callable $callback): mixed
{
    $original = setlocale(LC_ALL, '0');

    try {
        setlocale(LC_ALL, $locale);
        return $callback();
    } finally {
        setlocale(LC_ALL, $original); // Always restored
    }
}
```

## Why

- **Guaranteed Execution**: `finally` runs whether try succeeds, catch fires, or exception propagates
- **No Duplication**: Write cleanup once instead of in both try and catch
- **Resource Safety**: File handles, locks, connections always released
- **State Restoration**: Temporary changes always reverted
