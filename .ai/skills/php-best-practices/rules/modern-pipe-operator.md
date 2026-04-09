---
title: Pipe Operator
impact: HIGH
impactDescription: Enables readable left-to-right function chaining for data transformation
tags: modern-features, pipe-operator, functional, chaining, php85
phpVersion: "8.5+"
---

# Pipe Operator

Use the pipe operator (`|>`) for readable function chaining (PHP 8.5+).

## Bad Example

```php
<?php

declare(strict_types=1);

// Deeply nested function calls - read inside-out
$result = htmlspecialchars(
    strtolower(
        trim(
            $input
        )
    )
);

// Temporary variables - cluttered
$step1 = trim($input);
$step2 = strtolower($step1);
$step3 = htmlspecialchars($step2);
$result = $step3;

// Array processing - hard to follow
$slugs = array_unique(
    array_filter(
        array_map(
            fn(string $tag) => strtolower(trim($tag)),
            explode(',', $tags)
        ),
        fn(string $tag) => $tag !== ''
    )
);
```

## Good Example

```php
<?php

declare(strict_types=1);

// Pipe operator - read left-to-right, top-to-bottom
$result = $input
    |> trim(...)
    |> strtolower(...)
    |> htmlspecialchars(...);

// Chain with first-class callables
$length = "Hello World"
    |> trim(...)
    |> strlen(...);

// Use arrow functions for multi-argument functions
$slugs = $tags
    |> (fn(string $s) => explode(',', $s))
    |> (fn(array $a) => array_map(fn(string $t) => strtolower(trim($t)), $a))
    |> (fn(array $a) => array_filter($a, fn(string $t) => $t !== ''))
    |> array_unique(...);

// Practical example: building a slug
$slug = $title
    |> trim(...)
    |> strtolower(...)
    |> (fn(string $s) => preg_replace('/[^a-z0-9]+/', '-', $s))
    |> (fn(string $s) => trim($s, '-'));

// Practical example: processing user input
$sanitized = $request->input('comment')
    |> trim(...)
    |> strip_tags(...)
    |> htmlspecialchars(...);
```

## Important

The pipe operator passes the left-hand value as the **sole argument** to the right-hand callable. `$x |> foo(...)` is equivalent to `foo($x)`. For multi-argument functions, wrap in an arrow function:

```php
// str_replace has 3 args - wrap in arrow function
$clean = $input |> (fn(string $s) => str_replace(' ', '-', $s));
```

## Why

- **Readable Flow**: Data transformations read left-to-right, top-to-bottom
- **No Nesting**: Eliminates deeply nested function calls
- **No Temp Variables**: Avoids cluttering scope with intermediate values
- **Functional Style**: Encourages composable, single-purpose functions
- **Sole Argument**: `$x |> fn(...)` is equivalent to `fn($x)` — wrap in closures for multi-argument functions
- **Pairs with First-Class Callables**: `trim(...)` syntax works naturally with `|>`
