---
name: php-best-practices
description: PHP 8.x modern patterns, PSR standards, and SOLID principles. Use when reviewing PHP code, checking type safety, auditing code quality, or ensuring PHP best practices. Triggers on "review PHP", "check PHP code", "audit PHP", or "PHP best practices".
license: MIT
metadata:
  author: php-community
  version: "2.1.0"
  phpVersion: "8.0 - 8.5"
---

# PHP Best Practices

Modern PHP 8.x patterns, PSR standards, type system best practices, and SOLID principles. Contains 51 rules for writing clean, maintainable PHP code.

## Step 1: Detect PHP Version

**Always check the project's PHP version before giving any advice.** Features vary significantly across 8.0 - 8.5. Never suggest syntax that doesn't exist in the project's version.

Check `composer.json` for the required PHP version:
```json
{ "require": { "php": "^8.1" } }   // -> 8.1 rules and below
{ "require": { "php": "^8.3" } }   // -> 8.3 rules and below
{ "require": { "php": ">=8.4" } }  // -> 8.4 rules and below
```

Also check the runtime version:
```bash
php -v   # e.g. PHP 8.3.12
```

### Feature Availability by Version

| Feature | Version | Rule Prefix |
|---------|---------|-------------|
| Union types, match, nullsafe, named args, constructor promotion, attributes | 8.0+ | `type-`, `modern-` |
| Enums, readonly properties, intersection types, first-class callables, never, fibers | 8.1+ | `modern-` |
| Readonly classes, DNF types, true/false/null standalone types | 8.2+ | `modern-` |
| Typed class constants, `#[\Override]`, `json_validate()` | 8.3+ | `modern-` |
| Property hooks, asymmetric visibility, `#[\Deprecated]`, `new` without parens | 8.4+ | `modern-` |
| Pipe operator `|>` | 8.5+ | `modern-` |

**Only suggest features available in the detected version.** If the user asks about upgrading or newer features, mention what becomes available at each version.

## When to Apply

Reference these guidelines when:
- Writing or reviewing PHP code
- Implementing classes and interfaces
- Using PHP 8.x modern features
- Ensuring type safety
- Following PSR standards
- Applying design patterns

## Rule Categories by Priority

| Priority | Category | Impact | Prefix | Rules |
|----------|----------|--------|--------|-------|
| 1 | Type System | CRITICAL | `type-` | 9 |
| 2 | Modern PHP Features | CRITICAL | `modern-` | 16 |
| 3 | PSR Standards | HIGH | `psr-` | 6 |
| 4 | SOLID Principles | HIGH | `solid-` | 5 |
| 5 | Error Handling | HIGH | `error-` | 5 |
| 6 | Performance | MEDIUM | `perf-` | 5 |
| 7 | Security | CRITICAL | `sec-` | 5 |

## Quick Reference

### 1. Type System (CRITICAL) — 9 rules

- `type-strict-mode` - Declare strict types in every file
- `type-return-types` - Always declare return types
- `type-parameter-types` - Type all parameters
- `type-property-types` - Type class properties
- `type-union-types` - Use union types effectively
- `type-intersection-types` - Use intersection types
- `type-nullable-types` - Handle nullable types properly
- `type-void-never` - Use void/never for appropriate return types
- `type-mixed-avoid` - Avoid mixed type when possible

### 2. Modern PHP Features (CRITICAL) — 16 rules

**8.0+:**
- `modern-constructor-promotion` - Constructor property promotion
- `modern-match-expression` - Match over switch
- `modern-named-arguments` - Named arguments for clarity
- `modern-nullsafe-operator` - Nullsafe operator (?->)
- `modern-attributes` - Attributes for metadata

**8.1+:**
- `modern-enums` - Enums instead of constants
- `modern-enums-methods` - Enums with methods and interfaces
- `modern-readonly-properties` - Readonly for immutable data
- `modern-first-class-callables` - First-class callable syntax
- `modern-arrow-functions` - Arrow functions (7.4+, pairs well with 8.1 features)

**8.2+:**
- `modern-readonly-classes` - Readonly classes

**8.3+:**
- `modern-typed-constants` - Typed class constants (`const string NAME = 'foo'`)
- `modern-override-attribute` - `#[\Override]` to catch parent method typos

**8.4+:**
- `modern-property-hooks` - Property hooks replacing getters/setters
- `modern-asymmetric-visibility` - `public private(set)` for controlled access

**8.5+:**
- `modern-pipe-operator` - Pipe operator (`|>`) for functional chaining

### 3. PSR Standards (HIGH) — 6 rules

- `psr-4-autoloading` - Follow PSR-4 autoloading
- `psr-12-coding-style` - Follow PSR-12 coding style
- `psr-naming-classes` - Class naming conventions
- `psr-naming-methods` - Method naming conventions
- `psr-file-structure` - One class per file
- `psr-namespace-usage` - Proper namespace usage

### 4. SOLID Principles (HIGH) — 5 rules

- `solid-srp` - Single Responsibility: one reason to change
- `solid-ocp` - Open/Closed: extend, don't modify
- `solid-lsp` - Liskov Substitution: subtypes must be substitutable
- `solid-isp` - Interface Segregation: small, focused interfaces
- `solid-dip` - Dependency Inversion: depend on abstractions

### 5. Error Handling (HIGH) — 5 rules

- `error-custom-exceptions` - Create specific exceptions for different errors
- `error-exception-hierarchy` - Organize exceptions into meaningful hierarchy
- `error-try-catch-specific` - Catch specific exceptions, not generic \Exception
- `error-finally-cleanup` - Use finally for guaranteed resource cleanup
- `error-never-suppress` - Never use @ error suppression operator

### 6. Performance (MEDIUM) — 5 rules

- `perf-avoid-globals` - Avoid global variables, use dependency injection
- `perf-lazy-loading` - Defer expensive operations until needed
- `perf-array-functions` - Use native array functions over manual loops
- `perf-string-functions` - Use native string functions over regex
- `perf-generators` - Use generators for large datasets

### 7. Security (CRITICAL) — 5 rules

- `sec-input-validation` - Validate and sanitize all external input
- `sec-output-escaping` - Escape output based on context (HTML, JS, URL)
- `sec-password-hashing` - Use password_hash/verify, never MD5/SHA1
- `sec-sql-prepared` - Use prepared statements for all SQL queries
- `sec-file-uploads` - Validate file type, size, name; store outside web root

## Essential Guidelines

For detailed examples and explanations, see the rule files:

- [type-strict-mode.md](rules/type-strict-mode.md) - Strict types declaration
- [modern-constructor-promotion.md](rules/modern-constructor-promotion.md) - Constructor property promotion
- [modern-enums.md](rules/modern-enums.md) - PHP 8.1+ enums with methods
- [solid-srp.md](rules/solid-srp.md) - Single responsibility principle

### Key Patterns (Quick Reference)

```php
<?php
declare(strict_types=1);

// 8.0+ Constructor promotion + readonly (8.1+)
class User
{
    public function __construct(
        public readonly string $id,
        private string $email,
    ) {}
}

// 8.1+ Enums with methods
enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}

// 8.0+ Match expression
$result = match($status) {
    'pending' => 'Waiting',
    'active' => 'Running',
    default => 'Unknown',
};

// 8.0+ Nullsafe operator
$country = $user?->getAddress()?->getCountry();

// 8.3+ Typed class constants + #[\Override]
class PaymentService extends BaseService
{
    public const string GATEWAY = 'stripe';

    #[\Override]
    public function process(): void { /* ... */ }
}

// 8.4+ Property hooks + asymmetric visibility
class Product
{
    public string $name { set => trim($value); }
    public private(set) float $price;
}

// 8.5+ Pipe operator
$result = $input
    |> trim(...)
    |> strtolower(...)
    |> htmlspecialchars(...);
```

## Output Format

When auditing code, output findings in this format:

```
file:line - [category] Description of issue
```

Example:
```
src/Services/UserService.php:15 - [type] Missing return type declaration
src/Models/Order.php:42 - [modern] Use match expression instead of switch
src/Controllers/ApiController.php:28 - [solid] Class has multiple responsibilities
```

## How to Use

Read individual rule files for detailed explanations:

```
rules/modern-constructor-promotion.md
rules/type-strict-mode.md
rules/solid-srp.md
```
