---
title: Prepared Statements
impact: CRITICAL
impactDescription: String-concatenated SQL enables SQL injection - the most dangerous web vulnerability
tags: security, sql-injection, prepared-statements, pdo, php8
---

# Prepared Statements

Always use prepared statements with parameter binding for SQL queries. Never concatenate user input into SQL strings.

## Bad Example

```php
<?php

declare(strict_types=1);

// String concatenation - SQL injection vulnerable
$email = $_POST['email'];
$result = $db->query("SELECT * FROM users WHERE email = '$email'");
// Input: ' OR '1'='1  →  SELECT * FROM users WHERE email = '' OR '1'='1'

// Variable interpolation - equally vulnerable
$id = $_GET['id'];
$result = $db->query("SELECT * FROM orders WHERE id = $id");
// Input: 1; DROP TABLE orders  →  catastrophic

// sprintf - still vulnerable
$sql = sprintf("SELECT * FROM users WHERE name = '%s'", $name);

// Even with type casting - fragile and error-prone
$id = (int) $_GET['id']; // What if you forget the cast?
```

## Good Example

```php
<?php

declare(strict_types=1);

// PDO with named parameters
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// PDO with positional parameters
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND status = ?');
$stmt->execute([$id, $status]);

// Explicit type binding for non-string values
$stmt = $pdo->prepare('SELECT * FROM orders WHERE total > :min LIMIT :limit');
$stmt->bindValue(':min', $minAmount, PDO::PARAM_STR);
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->execute();

// INSERT with prepared statement
$stmt = $pdo->prepare(
    'INSERT INTO users (name, email, created_at) VALUES (:name, :email, NOW())'
);
$stmt->execute([
    'name' => $name,
    'email' => $email,
]);

// PDO configuration for maximum safety
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
]);

// Dynamic column names - whitelist, never bind
$allowed = ['name', 'email', 'created_at'];
$column = in_array($sortBy, $allowed, true) ? $sortBy : 'id';
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY {$column} ASC");
```

## Why

- **SQL Injection Prevention**: Parameters are never interpreted as SQL
- **Automatic Escaping**: Database driver handles escaping correctly
- **Performance**: Prepared statements can be reused for repeated queries
- **EMULATE_PREPARES = false**: Forces real server-side preparation
- **Column Names**: Cannot be parameterized - must use whitelist validation
