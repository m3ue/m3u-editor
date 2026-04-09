---
title: Override Attribute
impact: HIGH
impactDescription: Catches silent bugs from misspelled or removed parent methods
tags: modern-features, override, attributes, inheritance, php83
phpVersion: "8.3+"
---

# Override Attribute

Use `#[\Override]` on methods that override a parent method (PHP 8.3+).

## Bad Example

```php
<?php

declare(strict_types=1);

class BaseRepository
{
    public function findById(int $id): ?object
    {
        // Base implementation
        return null;
    }

    public function save(object $entity): void
    {
        // Base implementation
    }
}

class UserRepository extends BaseRepository
{
    // Typo in method name - creates new method instead of overriding!
    public function findByld(int $id): ?User  // 'l' instead of 'I'
    {
        return User::find($id);
    }

    // Parent renames save() to persist() - this silently becomes dead code
    public function save(object $entity): void
    {
        // This method is never called if parent renames it
    }
}
```

## Good Example

```php
<?php

declare(strict_types=1);

class BaseRepository
{
    public function findById(int $id): ?object
    {
        return null;
    }

    public function save(object $entity): void
    {
        // Base implementation
    }
}

class UserRepository extends BaseRepository
{
    // Typo caught when class is loaded!
    // #[\Override]
    // public function findByld(int $id): ?User  // Fatal error: method does not override

    #[\Override]
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    #[\Override]
    public function save(object $entity): void
    {
        // If parent renames save(), this throws a fatal error
        $entity->save();
    }
}

// Works with interfaces too
interface Renderable
{
    public function render(): string;
}

class Button implements Renderable
{
    #[\Override]
    public function render(): string
    {
        return '<button>Click</button>';
    }
}

// Works with abstract classes
abstract class Controller
{
    abstract protected function authorize(): bool;
}

class UserController extends Controller
{
    #[\Override]
    protected function authorize(): bool
    {
        return auth()->check();
    }
}
```

## Why

- **Catches Typos**: Misspelled method names are caught when the class is loaded
- **Refactoring Safety**: Renaming or removing a parent method triggers an error
- **Clear Intent**: Signals that a method is meant to override a parent
- **Interface Changes**: Detects when an interface removes a method
- **Low Overhead**: Validated at class loading, no per-call cost
