---
title: Native Array Functions
impact: MEDIUM
impactDescription: Built-in array functions are optimized in C and faster than manual loops
tags: performance, arrays, native-functions, php8
---

# Native Array Functions

Use PHP's built-in array functions instead of manual loops. They are implemented in C and significantly faster.

## Bad Example

```php
<?php

declare(strict_types=1);

// Manual filtering
$activeUsers = [];
foreach ($users as $user) {
    if ($user->isActive()) {
        $activeUsers[] = $user;
    }
}

// Manual mapping
$emails = [];
foreach ($users as $user) {
    $emails[] = $user->getEmail();
}

// Manual checking
$hasAdmin = false;
foreach ($users as $user) {
    if ($user->isAdmin()) {
        $hasAdmin = true;
        break;
    }
}

// Manual unique
$seen = [];
$unique = [];
foreach ($items as $item) {
    if (!in_array($item->id, $seen, true)) {
        $seen[] = $item->id;
        $unique[] = $item;
    }
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Filter with arrow function
$activeUsers = array_filter($users, fn(User $u) => $u->isActive());

// Map with arrow function
$emails = array_map(fn(User $u) => $u->getEmail(), $users);

// Check existence
$hasAdmin = (bool) array_filter($users, fn(User $u) => $u->isAdmin());

// Combine filter + map
$activeEmails = array_map(
    fn(User $u) => $u->getEmail(),
    array_filter($users, fn(User $u) => $u->isActive()),
);

// Key-value building with array_column
$userNames = array_column($userData, 'name', 'id');

// Reduce for aggregation
$totalRevenue = array_reduce(
    $orders,
    fn(float $sum, Order $o) => $sum + $o->getTotal(),
    0.0,
);

// Array unpacking (spread)
$merged = [...$defaults, ...$overrides];

// Sorting with spaceship operator
usort($products, fn(Product $a, Product $b) => $a->price <=> $b->price);
```

## Why

- **Performance**: Native functions are implemented in C, faster than PHP loops
- **Concise**: One line instead of 5-6 lines of loop boilerplate
- **Functional Style**: Composable, chainable operations
- **Arrow Functions**: `fn() =>` pairs naturally with array functions
- **Immutable**: Most array functions return new arrays, leaving originals unchanged
