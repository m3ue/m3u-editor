---
title: Strict Types Declaration
impact: CRITICAL
impactDescription: Prevents type coercion bugs, enforces type safety
tags: type-system, strict-types, type-safety, php8
---

# Strict Types Declaration

`declare(strict_types=1)` enforces strict type checking for function arguments and return values. Without it, PHP silently coerces types, hiding bugs. Strict mode catches type errors early, improving code reliability.

## Bad Example

```php
<?php

// No strict types - silent coercion
function calculateTotal(int $price, int $quantity): int
{
    return $price * $quantity;
}

// These hide problems:
calculateTotal("10", "5");   // Returns 50 - numeric strings coerced to int
calculateTotal(10.99, 2);    // Returns 20 - float truncated to int (deprecated in 8.1)
// calculateTotal("abc", 2); // TypeError in PHP 8.0+ (non-numeric string)

// Missing from file
namespace App\Services;

class Calculator
{
    // ...
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Strict types - TypeError on wrong types
function calculateTotal(int $price, int $quantity): int
{
    return $price * $quantity;
}

calculateTotal(10, 5);       // Returns 50
calculateTotal("10", "5");   // TypeError
calculateTotal(10.99, 2);    // TypeError
```

## Declaration Rules

```php
<?php

// MUST be the first statement in the file
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class UserService
{
    // ...
}
```

```php
<?php

namespace App\Services; // Wrong - declare must come first

declare(strict_types=1);

class UserService
{
    // ...
}
```

## Scope

```php
<?php

declare(strict_types=1);

// Strict mode applies to function CALLS in this file
function addNumbers(int $a, int $b): int
{
    return $a + $b;
}

// This call is in strict mode
addNumbers(1, 2);     //
addNumbers("1", "2"); // TypeError

// Strict mode also applies to internal PHP function calls
strlen("hello");      // Works
// strlen(12345);     // TypeError - int given, string expected
```

## File-by-File Basis

```php
<?php
// file: src/Strict.php
declare(strict_types=1);

function strictFunction(int $n): int
{
    return $n * 2;
}
```

```php
<?php
// file: src/NonStrict.php
// No declare - weak mode

require_once 'Strict.php';

// Calls from weak mode file still coerce
strictFunction("5"); // Returns 10 - coercion happens at call site
```

## Return Type Enforcement

```php
<?php

declare(strict_types=1);

// Return type strictly enforced
function getPrice(): float
{
    return 99.99; // Must return float
}

function getCount(): int
{
    return 42; // Must return int
}

// This would cause TypeError
function broken(): int
{
    return "42"; // TypeError - can't return string as int
}
```

## With Nullable Types

```php
<?php

declare(strict_types=1);

function findUser(int $id): ?User
{
    // Must return User or null, nothing else
    return User::find($id);
}

function process(?string $data): void
{
    // $data must be string or null
}

process("hello"); //
process(null);    //
process(123);     // TypeError
```

## With Union Types

```php
<?php

declare(strict_types=1);

function format(string|int $value): string
{
    return (string) $value;
}

format("hello"); //
format(42);      //
format(3.14);    // TypeError - float not in union
```

## Best Practice Template

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RepositoryInterface;
use App\Models\User;
use App\Exceptions\UserNotFoundException;

final class UserService
{
    public function __construct(
        private readonly RepositoryInterface $repository,
    ) {}

    public function findById(int $id): User
    {
        $user = $this->repository->find($id);

        if ($user === null) {
            throw new UserNotFoundException($id);
        }

        return $user;
    }

    public function create(array $data): User
    {
        return $this->repository->create($data);
    }

    /**
     * @param array<int> $ids
     * @return array<User>
     */
    public function findMany(array $ids): array
    {
        return $this->repository->findMany($ids);
    }
}
```

## IDE/Static Analysis

```php
<?php

declare(strict_types=1);

// PHPStan/Psalm will catch type errors even more strictly
// Combined with strict_types, you get maximum type safety

/** @var positive-int $count */
$count = getCount();

/** @var non-empty-string $name */
$name = getName();
```

## Why

- **Type Safety**: Catches type bugs at runtime immediately
- **No Surprises**: No silent type coercion
- **Static Analysis**: Works with PHPStan/Psalm for maximum safety
- **Self-Documenting**: Code intent is explicit and enforced
- **Industry Standard**: Required for reliable modern PHP
