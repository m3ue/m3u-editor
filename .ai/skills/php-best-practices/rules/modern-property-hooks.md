---
title: Property Hooks
impact: HIGH
impactDescription: Replaces verbose getter/setter boilerplate with clean property-level logic
tags: modern-features, property-hooks, getters, setters, php84
phpVersion: "8.4+"
---

# Property Hooks

Use property hooks to define get/set logic directly on properties (PHP 8.4+).

## Bad Example

```php
<?php

declare(strict_types=1);

// Verbose getter/setter boilerplate
class User
{
    private string $firstName;
    private string $lastName;
    private string $email;

    public function __construct(string $firstName, string $lastName, string $email)
    {
        $this->setFirstName($firstName);
        $this->setLastName($lastName);
        $this->setEmail($email);
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $value): void
    {
        $this->firstName = ucfirst(strtolower($value));
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $value): void
    {
        if (strlen($value) < 2) {
            throw new \InvalidArgumentException('Too short');
        }
        $this->lastName = $value;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $value): void
    {
        $this->email = strtolower($value);
    }
}
```

## Good Example

```php
<?php

declare(strict_types=1);

class User
{
    // Short hook syntax for simple transforms
    public string $firstName {
        set => ucfirst(strtolower($value));
    }

    // Full hook syntax for validation
    public string $lastName {
        set {
            if (strlen($value) < 2) {
                throw new \InvalidArgumentException('Too short');
            }
            $this->lastName = $value;
        }
    }

    // Virtual property (get-only, no stored value)
    public string $fullName {
        get => $this->firstName . ' ' . $this->lastName;
    }

    // Transform on set
    public string $email {
        set => strtolower($value);
    }

    public function __construct(
        string $firstName,
        string $lastName,
        string $email,
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
    }
}

$user = new User('PETER', 'Peterson', 'PETER@EXAMPLE.COM');
echo $user->firstName;  // "Peter"
echo $user->fullName;   // "Peter Peterson"
echo $user->email;      // "peter@example.com"

// Works with constructor promotion too
class Product
{
    public function __construct(
        public string $name { set => trim($value); },
        public float $price { set => max(0, $value); },
    ) {}
}
```

## Why

- **Less Boilerplate**: Eliminates separate getter/setter methods
- **Co-located Logic**: Validation and transformation live with the property
- **Virtual Properties**: Computed values without storing data
- **Direct Access**: Use `$obj->prop` instead of `$obj->getProp()`
- **Constructor Compatible**: Works with constructor property promotion
