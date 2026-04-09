---
title: Asymmetric Visibility
impact: HIGH
impactDescription: Enables immutable-like public API without readonly limitations
tags: modern-features, asymmetric-visibility, access-control, encapsulation, php84
phpVersion: "8.4+"
---

# Asymmetric Visibility

Use asymmetric visibility to allow public reading but restrict writing (PHP 8.4+).

## Bad Example

```php
<?php

declare(strict_types=1);

// Using readonly - cannot modify even internally
readonly class Order
{
    public function __construct(
        public string $id,
        public string $status,      // Cannot update status after creation!
        public float $total,
    ) {}
}

// Using private with getters - verbose
class VerboseOrder
{
    public function __construct(
        private string $id,
        private string $status,
        private float $total,
    ) {}

    public function getId(): string { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getTotal(): float { return $this->total; }

    public function markPaid(): void
    {
        $this->status = 'paid';
    }
}

echo $order->getStatus(); // Verbose
```

## Good Example

```php
<?php

declare(strict_types=1);

class Order
{
    public function __construct(
        // Publicly readable, only settable inside the class
        public private(set) string $id,
        public private(set) string $status,
        public private(set) float $total,
    ) {}

    public function markPaid(): void
    {
        $this->status = 'paid'; // OK - internal set
    }

    public function applyDiscount(float $percent): void
    {
        $this->total *= (1 - $percent / 100); // OK - internal set
    }
}

$order = new Order('ORD-001', 'pending', 99.99);
echo $order->status;      // OK - public read: "pending"
// $order->status = 'paid'; // Error! Cannot set from outside

$order->markPaid();
echo $order->status;      // "paid"

// Also works with protected(set)
class BaseModel
{
    public protected(set) string $table;
    public protected(set) array $fillable = [];
}

class User extends BaseModel
{
    public function __construct()
    {
        $this->table = 'users';           // OK - child class can set
        $this->fillable = ['name', 'email']; // OK
    }
}

$user = new User();
echo $user->table;       // OK - public read
// $user->table = 'foo'; // Error! Cannot set from outside
```

## Why

- **Clean Public API**: `$order->status` instead of `$order->getStatus()`
- **Internal Mutability**: Unlike readonly, the class can still update its own properties
- **No Getter Boilerplate**: Eliminates trivial getter methods
- **Granular Control**: Choose `private(set)` or `protected(set)` for write access
- **Pairs with Hooks**: Combine with property hooks for validated writes
