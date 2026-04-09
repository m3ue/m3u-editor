---
title: Custom Exceptions
impact: HIGH
impactDescription: Specific exceptions enable precise error handling and better debugging
tags: error-handling, exceptions, custom-exceptions, php8
---

# Custom Exceptions

Create specific exception classes for different error scenarios instead of using generic exceptions.

## Bad Example

```php
<?php

declare(strict_types=1);

class UserService
{
    public function register(array $data): User
    {
        if (empty($data['email'])) {
            throw new \Exception('Email is required');
        }

        if ($this->repository->findByEmail($data['email'])) {
            throw new \Exception('Email already exists');
        }

        if (!$this->gateway->charge($data['amount'])) {
            throw new \Exception('Payment failed');
        }

        // All errors are generic \Exception - caller can't distinguish them
        return $this->repository->create($data);
    }
}

// Caller has no way to handle specific errors
try {
    $service->register($data);
} catch (\Exception $e) {
    // Is this validation? Duplicate? Payment? No way to know without string matching
    echo $e->getMessage();
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Domain-specific exceptions
class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'Validation failed',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

class DuplicateEmailException extends \RuntimeException
{
    public function __construct(
        private readonly string $email,
    ) {
        parent::__construct("Email already registered: {$email}");
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}

class PaymentFailedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        private readonly ?string $transactionId = null,
    ) {
        parent::__construct("Payment failed: {$reason}");
    }
}

class UserService
{
    public function register(array $data): User
    {
        if (empty($data['email'])) {
            throw new ValidationException(['email' => 'Email is required']);
        }

        if ($this->repository->findByEmail($data['email'])) {
            throw new DuplicateEmailException($data['email']);
        }

        if (!$this->gateway->charge($data['amount'])) {
            throw new PaymentFailedException('Card declined');
        }

        return $this->repository->create($data);
    }
}

// Caller can handle each error type differently
try {
    $service->register($data);
} catch (ValidationException $e) {
    return response()->json(['errors' => $e->getErrors()], 422);
} catch (DuplicateEmailException $e) {
    return response()->json(['error' => 'Email already taken'], 409);
} catch (PaymentFailedException $e) {
    return response()->json(['error' => 'Payment failed'], 402);
}
```

## Why

- **Precise Handling**: Callers can catch and handle specific error types
- **Context Preservation**: Custom exceptions carry domain-specific data (email, errors array)
- **Self-Documenting**: Exception class names describe what went wrong
- **Type Safety**: IDE and static analysis can verify catch blocks
- **No String Matching**: No need to parse exception messages to determine error type
