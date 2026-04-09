# PHP Best Practices - Complete Guide

**Version:** 2.1.0
**Focus:** PHP 8.0 - 8.5, PSR Standards, Modern PHP Features
**Rules:** 51 (9 type + 16 modern + 6 PSR + 5 SOLID + 5 error + 5 perf + 5 security)
**License:** MIT

---

## Step 1: Detect PHP Version

**Always check the project's PHP version before giving advice.** Features vary across 8.0 - 8.5.

```bash
# Check composer.json
grep '"php"' composer.json    # e.g. "^8.2"

# Check runtime
php -v                        # e.g. PHP 8.3.12
```

**Only suggest features available in the detected version:**

| Version | Key Features Added |
|---------|-------------------|
| 8.0+ | Union types, match, nullsafe, named args, constructor promotion, attributes |
| 8.1+ | Enums, readonly props, intersection types, first-class callables, never |
| 8.2+ | Readonly classes, DNF types |
| 8.3+ | Typed class constants, `#[\Override]`, `json_validate()` |
| 8.4+ | Property hooks, asymmetric visibility, `#[\Deprecated]`, `new` without parens |
| 8.5+ | Pipe operator `\|>` |

## Overview

Comprehensive PHP 8.x best practices covering type system, modern features, PSR standards, SOLID principles, error handling, performance, and security. Each rule includes bad and good examples with detailed explanations.

## Categories

1. **Type System (CRITICAL)** - Strict types, return types, union types, null handling
2. **Modern PHP Features (CRITICAL)** - Constructor promotion, enums, readonly, match, property hooks, pipe operator
3. **PSR Standards (HIGH)** - PSR-4 autoloading, PSR-12 coding style, naming conventions
4. **SOLID Principles (HIGH)** - SRP, OCP, LSP, ISP, DIP
5. **Error Handling (HIGH)** - Custom exceptions, proper try-catch, error recovery
6. **Performance (MEDIUM)** - Generators, lazy loading, optimization techniques
7. **Security (CRITICAL)** - Input validation, output escaping, password hashing, SQL injection prevention

---

## 1. Type System

### 1.1 Strict Types Declaration

**Impact:** CRITICAL

Always enable strict type checking at the beginning of every PHP file with `declare(strict_types=1)`.

**Why:** Prevents silent type coercion bugs, catches type errors immediately, enables better static analysis.

**Bad:**
```php
<?php
// No strict types
function add(int $a, int $b): int {
    return $a + $b;
}
add("5", "10"); // Returns 15 - strings coerced silently
```

**Good:**
```php
<?php

declare(strict_types=1);

function add(int $a, int $b): int {
    return $a + $b;
}
add(5, 10);     // OK: Returns 15
add("5", "10"); // Error: TypeError
```

---

### 1.2 Return Type Declarations

**Impact:** CRITICAL

Always declare return types for all methods and functions.

**Why:** Self-documenting, enforces contracts, enables IDE autocompletion, catches return type mismatches.

**Bad:**
```php
<?php
class UserRepository {
    public function find($id) {
        return $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class UserRepository {
    public function find(int $id): ?User
    {
        $data = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        return $data ? new User($data) : null;
    }
}
```

---

### 1.3 Parameter Type Declarations

**Impact:** CRITICAL

Always declare parameter types for all function and method parameters.

**Bad:**
```php
<?php
function createOrder($user, $items) {
    // What is $user? What format should $items be?
}
```

**Good:**
```php
<?php

declare(strict_types=1);

function createOrder(User $user, array $items): Order {
    $order = new Order($user);
    foreach ($items as $item) {
        $order->addItem($item);
    }
    return $order;
}
```

---

### 1.4 Property Type Declarations

**Impact:** CRITICAL

Always declare types for class properties (PHP 7.4+).

**Bad:**
```php
<?php
class Product {
    private $id;
    private $name;
    private $price;
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class Product {
    private int $id;
    private string $name;
    private float $price;
    private array $categories = [];
}
```

---

### 1.5 Union Types

**Impact:** HIGH

Use union types when a value can legitimately be one of multiple types (PHP 8.0+).

**Bad:**
```php
<?php
class DataLoader {
    /**
     * @param string|array $source
     */
    public function load($source) {
        // Relies on docblock, no type enforcement
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class DataLoader {
    public function load(string|array $source): array
    {
        if (is_string($source)) {
            return $this->loadFromFile($source);
        }
        return $this->loadFromArray($source);
    }
}
```

---

### 1.6 Nullable Types

**Impact:** CRITICAL

Use nullable types explicitly when null is a valid value.

**Bad:**
```php
<?php
class UserService {
    public function findByEmail(string $email) {
        // Unclear if null is valid return
        return $this->repository->find($email) ?: null;
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class UserService {
    public function findByEmail(string $email): ?User
    {
        return $this->repository->find($email);
    }
}
```

---

## 2. Modern PHP Features

### 2.1 Constructor Property Promotion

**Impact:** CRITICAL

Use constructor property promotion to reduce boilerplate (PHP 8.0+).

**Bad:**
```php
<?php
class User {
    private string $id;
    private string $name;
    private string $email;
    
    public function __construct(string $id, string $name, string $email) {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class User {
    public function __construct(
        private string $id,
        private string $name,
        private string $email,
    ) {}
}
```

---

### 2.2 Type-Safe Enums

**Impact:** CRITICAL

Use enums instead of class constants for finite sets of values (PHP 8.1+).

**Bad:**
```php
<?php
class OrderStatus {
    public const PENDING = 'pending';
    public const SHIPPED = 'shipped';
}

function updateStatus(string $status): void {
    // 'invalid' would be accepted
}
```

**Good:**
```php
<?php

declare(strict_types=1);

enum OrderStatus: string {
    case Pending = 'pending';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    
    public function label(): string {
        return match($this) {
            self::Pending => 'Awaiting Processing',
            self::Shipped => 'On the Way',
            self::Delivered => 'Delivered',
        };
    }
}

function updateStatus(OrderStatus $status): void {
    // Only valid enum values accepted
}
```

---

### 2.3 Readonly Properties

**Impact:** CRITICAL

Use readonly properties for immutable data (PHP 8.1+).

**Bad:**
```php
<?php
class Invoice {
    private string $invoiceNumber;
    
    // Setter allows modification after creation
    public function setInvoiceNumber(string $number): void {
        $this->invoiceNumber = $number;
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class Invoice {
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly DateTimeImmutable $issuedAt,
        public readonly float $amount,
    ) {}
    
    // No setters - properties are immutable
}
```

---

### 2.4 Match Expression

**Impact:** HIGH

Use match expressions instead of switch for cleaner, type-safe code (PHP 8.0+).

**Bad:**
```php
<?php
function getStatusMessage(int $code): string {
    switch ($code) {
        case 200:
            $message = 'OK';
            break;
        case 404:
            $message = 'Not Found';
            break;
        default:
            $message = 'Unknown';
    }
    return $message;
}
```

**Good:**
```php
<?php

declare(strict_types=1);

function getStatusMessage(int $code): string {
    return match ($code) {
        200 => 'OK',
        404 => 'Not Found',
        default => 'Unknown',
    };
}
```

---

### 2.5 Nullsafe Operator

**Impact:** HIGH

Use the nullsafe operator for cleaner null checking chains (PHP 8.0+).

**Bad:**
```php
<?php
function getCountry(?Order $order): ?string {
    if ($order !== null) {
        $customer = $order->getCustomer();
        if ($customer !== null) {
            $address = $customer->getAddress();
            if ($address !== null) {
                return $address->getCountry();
            }
        }
    }
    return null;
}
```

**Good:**
```php
<?php

declare(strict_types=1);

function getCountry(?Order $order): ?string {
    return $order?->getCustomer()?->getAddress()?->getCountry();
}
```

---

### 2.6 Arrow Functions

**Impact:** MEDIUM

Use arrow functions for short, single-expression closures (PHP 7.4+).

**Bad:**
```php
<?php
$doubled = array_map(function ($n) {
    return $n * 2;
}, $numbers);

$multiplier = 3;
$multiplied = array_map(function ($n) use ($multiplier) {
    return $n * $multiplier;
}, $numbers);
```

**Good:**
```php
<?php

declare(strict_types=1);

$doubled = array_map(fn($n) => $n * 2, $numbers);

$multiplier = 3;
$multiplied = array_map(fn($n) => $n * $multiplier, $numbers);
```

---

### 2.7 Typed Class Constants (8.3+)

**Impact:** HIGH

Add type declarations to class constants for type safety.

**Bad:**
```php
<?php
class Config {
    public const TIMEOUT = 30;       // int? string? no enforcement
    public const NAME = 'my-app';
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class Config {
    public const int TIMEOUT = 30;
    public const string NAME = 'my-app';
    public const array ALLOWED = ['read', 'write'];
}
```

---

### 2.8 Override Attribute (8.3+)

**Impact:** HIGH

Use `#[\Override]` on methods that override a parent to catch typos and refactoring errors.

**Bad:**
```php
<?php
class UserRepo extends BaseRepo {
    public function findByld(int $id): ?User { /* typo: 'l' not 'I' */ }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class UserRepo extends BaseRepo {
    #[\Override]
    public function findById(int $id): ?User { /* typo caught when class is loaded */ }
}
```

---

### 2.9 Property Hooks (8.4+)

**Impact:** HIGH

Use property hooks to define get/set logic directly on properties.

**Bad:**
```php
<?php
class User {
    private string $name;
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = ucfirst($v); }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class User {
    public string $name { set => ucfirst(strtolower($value)); }
    public string $fullName { get => $this->firstName . ' ' . $this->lastName; }
}
```

---

### 2.10 Asymmetric Visibility (8.4+)

**Impact:** HIGH

Use `public private(set)` for properties that are publicly readable but privately writable.

**Bad:**
```php
<?php
class Order {
    private string $status;
    public function getStatus(): string { return $this->status; }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class Order {
    public private(set) string $status = 'pending';

    public function markPaid(): void {
        $this->status = 'paid'; // OK - internal set
    }
}
echo $order->status;      // OK - public read
// $order->status = 'x';  // Error - private set
```

---

### 2.11 Pipe Operator (8.5+)

**Impact:** HIGH

Use the pipe operator for readable left-to-right function chaining.

**Bad:**
```php
<?php
$result = htmlspecialchars(strtolower(trim($input)));
```

**Good:**
```php
<?php

declare(strict_types=1);

$result = $input
    |> trim(...)
    |> strtolower(...)
    |> htmlspecialchars(...);
```

---

## 3. PSR Standards

### 3.1 PSR-4 Autoloading

**Impact:** CRITICAL

Follow PSR-4 autoloading standard for class file organization.

**Structure:**
```
src/
  Domain/
    User/
      User.php          -> App\Domain\User\User
      UserRepository.php
  Application/
    Services/
      UserService.php   -> App\Application\Services\UserService
```

**composer.json:**
```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

---

### 3.2 PSR-12 Coding Style

**Impact:** HIGH

Follow PSR-12 extended coding style for consistent, readable code.

**Key Rules:**
- Opening braces on their own line for classes, methods, and functions
- One blank line after namespace and use blocks
- Spaces after control structure keywords
- One blank line between methods
- Type declarations with no space before colon

**Good:**
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\User\User;
use App\Domain\User\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $repository,
    ) {}

    public function find(int $id): ?User
    {
        if ($id < 1) {
            return null;
        }

        return $this->repository->find($id);
    }
}
```

---

### 3.3 Class Naming Conventions

**Impact:** HIGH

Use PascalCase with descriptive, intention-revealing names.

**Patterns:**
- Entities: `User`, `Order`, `Product`
- Services: `UserService`, `OrderService`
- Repositories: `UserRepository`, `OrderRepository`
- Controllers: `UserController`, `OrderController`
- Commands: `CreateUserCommand`, `ProcessPaymentCommand`
- Events: `UserCreated`, `OrderShipped` (past tense)
- Exceptions: `UserNotFoundException`, `InvalidPaymentMethodException`
- Interfaces: `Cacheable`, `UserRepository`, `PaymentGateway`

---

## 4. SOLID Principles

### 4.1 Single Responsibility Principle

**Impact:** CRITICAL

A class should have only one reason to change.

**Bad:**
```php
<?php
class User {
    // Multiple responsibilities
    public function save(): void { /* DB */ }
    public function sendEmail(): void { /* Email */ }
    public function toJson(): string { /* Serialization */ }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class User {
    // Just user data
}

class UserRepository {
    public function save(User $user): void { /* DB */ }
}

class UserMailer {
    public function sendWelcome(User $user): void { /* Email */ }
}

class UserSerializer {
    public function toJson(User $user): string { /* Serialization */ }
}
```

---

### 4.2 Open/Closed Principle

**Impact:** HIGH

Classes should be open for extension but closed for modification.

**Bad:**
```php
<?php
class PaymentProcessor {
    public function process(string $type, float $amount): void {
        if ($type === 'credit_card') { /* ... */ }
        if ($type === 'paypal') { /* ... */ }
        // Adding new type requires modifying this class
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

interface PaymentMethod {
    public function process(Money $amount): PaymentResult;
}

class CreditCardPayment implements PaymentMethod {
    public function process(Money $amount): PaymentResult { /* ... */ }
}

class PayPalPayment implements PaymentMethod {
    public function process(Money $amount): PaymentResult { /* ... */ }
}

// Add new payment methods without modifying existing code
class CryptoPayment implements PaymentMethod {
    public function process(Money $amount): PaymentResult { /* ... */ }
}
```

---

### 4.3 Dependency Inversion Principle

**Impact:** CRITICAL

Depend on abstractions, not concretions.

**Bad:**
```php
<?php
class OrderService {
    private MySqlDatabase $db;
    
    public function __construct() {
        $this->db = new MySqlDatabase();
    }
}
```

**Good:**
```php
<?php

declare(strict_types=1);

interface OrderRepository {
    public function save(Order $order): void;
    public function find(OrderId $id): ?Order;
}

class OrderService {
    public function __construct(
        private OrderRepository $repository,
        private PaymentGateway $payment,
        private Logger $logger,
    ) {}
}

class DoctrineOrderRepository implements OrderRepository {
    // Implementation
}
```

---

## 5. Error Handling

### 5.1 Custom Exceptions

**Impact:** HIGH

Create specific exception classes instead of using generic `\Exception`.

**Bad:**
```php
<?php
throw new \Exception('Email already exists'); // Caller can't distinguish error types
```

**Good:**
```php
<?php

declare(strict_types=1);

class DuplicateEmailException extends \RuntimeException
{
    public function __construct(private readonly string $email)
    {
        parent::__construct("Email already registered: {$email}");
    }
}

// Caller handles specifically
try {
    $service->register($data);
} catch (DuplicateEmailException $e) {
    return response()->json(['error' => 'Email taken'], 409);
} catch (ValidationException $e) {
    return response()->json(['errors' => $e->getErrors()], 422);
}
```

---

### 5.2 Catch Specific Exceptions

**Impact:** HIGH

Never catch generic `\Exception` or `\Throwable` except at top-level error boundaries.

**Bad:**
```php
<?php
try {
    $user = $repo->find($id);
    $mailer->send($user);
} catch (\Exception $e) {
    return null; // Swallows ALL errors - hides bugs
}
```

**Good:**
```php
<?php

declare(strict_types=1);

class NotificationService
{
    public function notifyUser(int $id): void
    {
        try {
            $user = $this->repo->find($id);
            $this->mailer->send($user);
        } catch (UserNotFoundException $e) {
            return;
        } catch (MailerException $e) {
            $this->logger->error('Email failed', ['error' => $e->getMessage()]);
            // Continue - email is non-critical
        }
    }
}
```

---

### 5.3 Never Suppress Errors

**Impact:** CRITICAL

Never use the `@` operator. Handle errors explicitly.

**Bad:**
```php
<?php
$data = @file_get_contents($path); // Hides all errors
$conn = @mysqli_connect('localhost', 'user', 'pass');
```

**Good:**
```php
<?php

declare(strict_types=1);

if (!is_readable($path)) {
    throw new FileNotFoundException("Not readable: {$path}");
}
$data = file_get_contents($path);
```

---

## 6. Performance

### 6.1 Generators for Large Datasets

**Impact:** MEDIUM

Use generators to process large datasets without loading everything into memory.

**Bad:**
```php
<?php
function getAllUsers(): array {
    return $stmt->fetchAll(); // 1M users = huge memory spike
}
```

**Good:**
```php
<?php

declare(strict_types=1);

function getAllUsers(PDO $pdo, int $chunk = 1000): \Generator {
    $offset = 0;
    do {
        $stmt = $pdo->prepare('SELECT * FROM users LIMIT :l OFFSET :o');
        $stmt->bindValue(':l', $chunk, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            yield User::fromArray($row);
        }
        $offset += $chunk;
    } while (count($rows) === $chunk);
}
```

---

### 6.2 Native String Functions over Regex

**Impact:** MEDIUM

Use PHP 8.0+ string functions instead of regex for simple checks.

**Bad:**
```php
<?php
if (preg_match('/^https/', $url)) { /* starts with */ }
if (preg_match('/\.pdf$/', $file)) { /* ends with */ }
```

**Good:**
```php
<?php

declare(strict_types=1);

if (str_starts_with($url, 'https')) { /* 2-10x faster */ }
if (str_ends_with($file, '.pdf')) { /* clearer intent */ }
if (str_contains($role, 'admin')) { /* no escaping needed */ }
```

---

## 7. Security

### 7.1 Input Validation

**Impact:** CRITICAL

Always validate and sanitize user input.

**Good:**
```php
<?php

declare(strict_types=1);

function createUser(array $data): User {
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        throw new InvalidArgumentException('Invalid email');
    }
    
    $name = trim($data['name'] ?? '');
    if (strlen($name) < 2 || strlen($name) > 100) {
        throw new InvalidArgumentException('Name must be 2-100 characters');
    }
    
    return new User($email, $name);
}
```

---

### 7.2 Prepared Statements

**Impact:** CRITICAL

Always use prepared statements for SQL queries to prevent SQL injection.

**Bad:**
```php
<?php
$sql = "SELECT * FROM users WHERE email = '{$email}'";
$result = $db->query($sql); // SQL injection vulnerable
```

**Good:**
```php
<?php

declare(strict_types=1);

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$result = $stmt->fetch();
```

---

### 7.3 Password Hashing

**Impact:** CRITICAL

Use `password_hash()` and `password_verify()` for password security.

**Bad:**
```php
<?php
$hash = md5($password); // Insecure
$hash = sha1($password); // Insecure
```

**Good:**
```php
<?php

declare(strict_types=1);

// Hashing
$hash = password_hash($password, PASSWORD_ARGON2ID);

// Verification
if (password_verify($inputPassword, $storedHash)) {
    // Password correct
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
        // Rehash if algorithm changed
        $newHash = password_hash($inputPassword, PASSWORD_ARGON2ID);
    }
}
```

---

## References

- [PHP Manual](https://www.php.net/manual/en/)
- [PHP 8.3 Release](https://www.php.net/releases/8.3/en.php)
- [PSR Standards](https://www.php-fig.org/psr/)
- [PHPStan - Static Analysis](https://phpstan.org/)
- [Psalm - Static Analysis](https://psalm.dev/)
- [PHP The Right Way](https://phptherightway.com/)

---

**Last Updated:** March 2026
**Version:** 2.1.0
**License:** MIT
