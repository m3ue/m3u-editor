---
title: Generators for Large Datasets
impact: MEDIUM
impactDescription: Generators process items one at a time, using constant memory regardless of dataset size
tags: performance, generators, memory, yield, php8
---

# Generators for Large Datasets

Use generators (`yield`) to process large datasets without loading everything into memory.

## Bad Example

```php
<?php

declare(strict_types=1);

// Loads entire file into memory - crashes on large files
function readLines(string $path): array
{
    return file($path); // 1GB file = 1GB+ memory
}

// Loads all records into memory
function getAllUsers(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM users');
    return $stmt->fetchAll(); // 1M users = huge memory spike
}

// Building large array in memory
function generateRange(int $start, int $end): array
{
    $result = [];
    for ($i = $start; $i <= $end; $i++) {
        $result[] = $i;
    }
    return $result; // 10M items = 10M element array
}

foreach (getAllUsers() as $user) {
    processUser($user); // All users loaded before loop starts
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Reads file line by line - constant memory
function readLines(string $path): \Generator
{
    $handle = fopen($path, 'r');

    try {
        while (($line = fgets($handle)) !== false) {
            yield trim($line);
        }
    } finally {
        fclose($handle);
    }
}

// Fetches records in chunks - constant memory
function getAllUsers(PDO $pdo, int $chunkSize = 1000): \Generator
{
    $offset = 0;

    do {
        $stmt = $pdo->prepare('SELECT * FROM users LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $chunkSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            yield User::fromArray($row);
        }

        $offset += $chunkSize;
    } while (count($rows) === $chunkSize);
}

// Lazy range - no array allocation
function lazyRange(int $start, int $end): \Generator
{
    for ($i = $start; $i <= $end; $i++) {
        yield $i;
    }
}

// Process one user at a time - constant memory
foreach (getAllUsers($pdo) as $user) {
    processUser($user);
}

// Chain generators with yield from
function activeUsers(PDO $pdo): \Generator
{
    foreach (getAllUsers($pdo) as $user) {
        if ($user->isActive()) {
            yield $user;
        }
    }
}
```

## Why

- **Constant Memory**: Process 1M records using the same memory as 1 record
- **Lazy Evaluation**: Items computed on demand, not all upfront
- **Composable**: Generators can be chained with `yield from`
- **File Processing**: Read multi-GB files without memory issues
- **Database Batching**: Fetch in chunks while presenting a simple iterator interface
