---
title: Native String Functions
impact: MEDIUM
impactDescription: Use built-in string functions over regex for simple operations
tags: performance, strings, native-functions, php8
---

# Native String Functions

Use PHP's built-in string functions instead of regex for simple operations. String functions are faster and more readable.

## Bad Example

```php
<?php

declare(strict_types=1);

// Regex for simple checks - overkill
if (preg_match('/^https/', $url)) {
    // starts with https
}

if (preg_match('/\.pdf$/', $filename)) {
    // ends with .pdf
}

if (preg_match('/admin/', $role)) {
    // contains admin
}

// Regex for simple replacements
$clean = preg_replace('/\s+/', ' ', $text);
$slug = preg_replace('/[^a-z0-9]/', '-', strtolower($title));
```

## Good Example

```php
<?php

declare(strict_types=1);

// str_starts_with (PHP 8.0+)
if (str_starts_with($url, 'https')) {
    // starts with https
}

// str_ends_with (PHP 8.0+)
if (str_ends_with($filename, '.pdf')) {
    // ends with .pdf
}

// str_contains (PHP 8.0+)
if (str_contains($role, 'admin')) {
    // contains admin
}

// String functions for simple operations
$trimmed = trim($input);
$lower = strtolower($name);
$upper = strtoupper($code);
$replaced = str_replace('old', 'new', $text);
$parts = explode(',', $csv);
$joined = implode(', ', $items);

// substr for extraction
$extension = substr($filename, strrpos($filename, '.') + 1);

// Use regex only for complex patterns
$isEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
$matches = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts);
```

## Why

- **Performance**: String functions are 2-10x faster than regex for simple operations
- **Readability**: `str_contains($s, 'admin')` is clearer than `preg_match('/admin/', $s)`
- **No Escaping**: No need to escape regex special characters
- **Type Safety**: String functions have strict parameter types
- **PHP 8.0+**: `str_starts_with`, `str_ends_with`, `str_contains` replace common regex patterns
