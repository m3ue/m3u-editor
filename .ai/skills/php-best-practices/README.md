# PHP Best Practices

Modern PHP 8.x patterns, PSR standards, and SOLID principles for clean, maintainable code.

## Overview

**Important:** Always detect the project's PHP version (`composer.json` or `php -v`) before giving advice. Only suggest features available in the detected version.

This skill provides guidance for:
- PHP 8.0 - 8.5 modern features (version-annotated)
- Type system best practices
- PSR standards compliance
- SOLID principles

## Categories (51 rules)

### 1. Type System (Critical) — 9 rules
Strict types, return types, union/intersection types, nullable handling, void/never.

### 2. Modern PHP Features (Critical) — 16 rules
8.0: constructor promotion, match, named args. 8.1: enums, readonly. 8.2: readonly classes. 8.3: typed constants, #[\Override]. 8.4: property hooks, asymmetric visibility. 8.5: pipe operator.

### 3. PSR Standards (High) — 6 rules
PSR-4 autoloading, PSR-12 coding style, naming conventions, file structure, namespaces.

### 4. SOLID Principles (High) — 5 rules
Single responsibility, open/closed, Liskov substitution, interface segregation, dependency inversion.

### 5. Error Handling (High) — 5 rules
Custom exceptions, exception hierarchy, specific catches, finally cleanup, never suppress errors.

### 6. Performance (Medium) — 5 rules
Generators, lazy loading, native array/string functions, avoiding globals.

### 7. Security (Critical) — 5 rules
Input validation, output escaping, password hashing, prepared statements, file upload security.

## Usage

Ask Claude to:
- "Review my PHP code"
- "Check PHP types"
- "Audit PHP for SOLID"
- "Check PHP best practices"

## Key Guidelines

### Always Use
- `declare(strict_types=1)` at file start
- Constructor property promotion
- Readonly properties for immutable data
- Enums instead of class constants
- Match expressions over switch
- Named arguments for clarity
- Type declarations everywhere

### Avoid
- Mixed type when specific type possible
- Hard-coded dependencies
- Fat interfaces
- Suppressing errors with @
- Global variables
- God classes

## References

- [PHP Manual](https://www.php.net/manual/)
- [PHP-FIG PSR Standards](https://www.php-fig.org/psr/)
- [PHP The Right Way](https://phptherightway.com/)
