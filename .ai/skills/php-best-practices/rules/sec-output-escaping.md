---
title: Output Escaping
impact: CRITICAL
impactDescription: Unescaped output enables XSS attacks and data corruption
tags: security, xss, output-escaping, htmlspecialchars, php8
---

# Output Escaping

Always escape output based on the context where it will be used to prevent XSS and injection attacks.

## Bad Example

```php
<?php

declare(strict_types=1);

// Raw user data in HTML - XSS vulnerable
echo "<h1>Welcome, {$user->name}</h1>";
echo "<p>{$user->bio}</p>";

// Raw data in HTML attributes
echo "<input value='{$searchTerm}'>";

// Raw data in JavaScript context
echo "<script>var name = '{$user->name}';</script>";

// Raw data in URL
echo "<a href='/search?q={$query}'>Search</a>";
```

## Good Example

```php
<?php

declare(strict_types=1);

// HTML context - htmlspecialchars with ENT_QUOTES
$safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
echo "<h1>Welcome, {$safeName}</h1>";

// HTML attributes - same escaping
$safeSearch = htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8');
echo "<input value=\"{$safeSearch}\">";

// JavaScript context - json_encode
$jsonName = json_encode($user->name, JSON_HEX_TAG | JSON_HEX_AMP);
echo "<script>var name = {$jsonName};</script>";

// URL context - urlencode
$safeQuery = urlencode($query);
echo "<a href=\"/search?q={$safeQuery}\">Search</a>";

// Helper function for consistent escaping
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

echo "<p>" . e($user->bio) . "</p>";

// In templating engines (Blade, Twig) - auto-escaping is default
// Blade: {{ $user->name }}         - escaped
// Blade: {!! $trustedHtml !!}      - raw (only for trusted content)
// Twig:  {{ user.name }}           - escaped
// Twig:  {{ user.name|raw }}       - raw (only for trusted content)
```

## Why

- **Prevents XSS**: Malicious scripts can't execute through escaped output
- **Context-Aware**: HTML, JavaScript, URL, and CSS each need different escaping
- **ENT_QUOTES**: Escapes both single and double quotes
- **UTF-8**: Always specify encoding to prevent multi-byte attacks
- **Template Engines**: Use frameworks with auto-escaping (Blade, Twig)
