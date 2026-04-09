---
title: Input Validation
impact: CRITICAL
impactDescription: Unvalidated input is the root cause of most security vulnerabilities
tags: security, validation, input, sanitization, php8
---

# Input Validation

Always validate and sanitize all external input before using it. Never trust data from users, APIs, or any external source.

## Bad Example

```php
<?php

declare(strict_types=1);

// Using raw input directly
$name = $_POST['name'];
$email = $_POST['email'];
$age = $_POST['age'];

$user = new User($name, $email, $age);
$repository->save($user);

// Trusting query parameters
$page = $_GET['page'];
$sortBy = $_GET['sort']; // Could be "id; DROP TABLE users"
$results = $db->query("SELECT * FROM items ORDER BY {$sortBy} LIMIT {$page}");
```

## Good Example

```php
<?php

declare(strict_types=1);

// Validate types and constraints
function createUser(array $input): User
{
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        throw new ValidationException(['email' => 'Invalid email address']);
    }

    $name = trim($input['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) {
        throw new ValidationException(['name' => 'Name must be 1-100 characters']);
    }

    $age = filter_var($input['age'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 150],
    ]);
    if ($age === false) {
        throw new ValidationException(['age' => 'Age must be between 1 and 150']);
    }

    return new User($name, $email, $age);
}

// Whitelist for dynamic columns
function getResults(PDO $pdo, array $input): array
{
    $allowedColumns = ['id', 'name', 'created_at', 'price'];
    $sortBy = in_array($input['sort'] ?? '', $allowedColumns, true)
        ? $input['sort']
        : 'id';

    $page = max(1, (int) ($input['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        "SELECT * FROM items ORDER BY {$sortBy} LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
```

## Why

- **Prevents Injection**: SQL injection, XSS, command injection all start with unvalidated input
- **Data Integrity**: Ensures only valid data enters the system
- **Whitelist Over Blacklist**: Whitelist allowed values instead of trying to block bad ones
- **Type Coercion**: `filter_var` with FILTER_VALIDATE_INT returns false for non-integers
- **Defense in Depth**: Validate at every boundary, not just the frontend
