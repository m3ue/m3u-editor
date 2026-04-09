---
title: Typed Class Constants
impact: HIGH
impactDescription: Prevents type errors in class constants and improves static analysis
tags: modern-features, typed-constants, type-safety, php83
phpVersion: "8.3+"
---

# Typed Class Constants

Use typed class constants to enforce type safety on constant values (PHP 8.3+).

## Bad Example

```php
<?php

declare(strict_types=1);

// Untyped constants - no type checking
class PaymentGateway
{
    public const TIMEOUT = 30;
    public const CURRENCY = 'USD';
    public const RETRY_LIMIT = 3;
    public const ENABLED = true;
}

// Child class can accidentally change the type
class StripeGateway extends PaymentGateway
{
    public const TIMEOUT = '30'; // Changed from int to string - no error!
    public const ENABLED = 1;    // Changed from bool to int - no error!
}

interface HasVersion
{
    public const VERSION = '1.0.0';
}

class App implements HasVersion
{
    public const VERSION = 100; // Type changed silently - no error!
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Typed constants enforce type safety
class PaymentGateway
{
    public const int TIMEOUT = 30;
    public const string CURRENCY = 'USD';
    public const int RETRY_LIMIT = 3;
    public const bool ENABLED = true;

    // Array constants with typed declaration
    public const array SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP'];
}

// Child class cannot change the type
class StripeGateway extends PaymentGateway
{
    public const int TIMEOUT = 60;        // OK - same type
    // public const string TIMEOUT = '60'; // TypeError!
}

// Interfaces with typed constants
interface HasVersion
{
    public const string VERSION = '1.0.0';
}

class App implements HasVersion
{
    public const string VERSION = '2.0.0'; // OK - same type
    // public const int VERSION = 2;        // TypeError!
}

// Enums with typed constants
enum Status: string
{
    public const string DEFAULT = 'pending';

    case Pending = 'pending';
    case Active = 'active';
}
```

## Why

- **Type Safety**: Constants are validated when the class is loaded
- **Inheritance Protection**: Child classes cannot change constant types
- **Interface Contracts**: Typed constants in interfaces enforce implementation types
- **Static Analysis**: Tools like PHPStan and Psalm can validate constant usage
- **Self-Documenting**: Type declaration makes intent clear
