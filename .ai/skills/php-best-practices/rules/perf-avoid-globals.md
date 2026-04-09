---
title: Avoid Global Variables
impact: MEDIUM
impactDescription: Globals create hidden dependencies and make code untestable
tags: performance, globals, dependency-injection, php8
---

# Avoid Global Variables

Never use global variables or the `global` keyword. Use dependency injection instead.

## Bad Example

```php
<?php

declare(strict_types=1);

// Global state - hidden dependency
$db = new PDO('mysql:host=localhost;dbname=app', 'root', '');
$config = ['debug' => true, 'cache_ttl' => 3600];

class UserService
{
    public function find(int $id): ?User
    {
        global $db; // Hidden dependency
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function isDebug(): bool
    {
        global $config; // Another hidden dependency
        return $config['debug'];
    }
}

// Problems:
// - Can't test without setting up globals
// - Can't trace where $db comes from
// - Any code can modify $db or $config at any time
```

## Good Example

```php
<?php

declare(strict_types=1);

class UserService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Config $config,
    ) {}

    public function find(int $id): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return $data ? User::fromArray($data) : null;
    }

    public function isDebug(): bool
    {
        return $this->config->get('debug', false);
    }
}

// Dependencies are explicit, injectable, and testable
$service = new UserService($pdo, $config);

// Easy to test with mocks
$service = new UserService($mockPdo, new Config(['debug' => false]));
```

## Why

- **Explicit Dependencies**: Constructor shows exactly what a class needs
- **Testability**: Dependencies can be mocked or stubbed
- **No Side Effects**: No code can silently change shared state
- **Thread Safety**: No shared mutable state
- **Refactoring**: IDE can track all usages through typed properties
