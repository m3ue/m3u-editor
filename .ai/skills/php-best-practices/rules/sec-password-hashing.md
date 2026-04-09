---
title: Password Hashing
impact: CRITICAL
impactDescription: Weak password storage enables mass credential compromise in data breaches
tags: security, passwords, hashing, bcrypt, argon2, php8
---

# Password Hashing

Always use `password_hash()` and `password_verify()` for password security. Never use MD5, SHA1, or plain text.

## Bad Example

```php
<?php

declare(strict_types=1);

// Plain text - worst possible
$password = $_POST['password'];
$db->insert('users', ['password' => $password]);

// MD5 - trivially crackable
$hash = md5($password);

// SHA1 - also trivially crackable
$hash = sha1($password);

// SHA256 without salt - still vulnerable to rainbow tables
$hash = hash('sha256', $password);

// Home-grown "salting" - reinventing the wheel poorly
$hash = hash('sha256', 'mysalt' . $password);

// Comparing hashes with == (timing attack vulnerable)
if ($storedHash == md5($inputPassword)) {
    // login
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Hash with bcrypt (default) or Argon2id (recommended)
$hash = password_hash($password, PASSWORD_ARGON2ID);
// Or: password_hash($password, PASSWORD_DEFAULT); // bcrypt

// Verify - timing-safe comparison built in
if (password_verify($inputPassword, $storedHash)) {
    // Password correct

    // Rehash if algorithm or cost changed
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($inputPassword, PASSWORD_ARGON2ID);
        $repository->updatePasswordHash($userId, $newHash);
    }

    // Regenerate session after login
    session_regenerate_id(true);
}

// Custom Argon2id options for high-security applications
$hash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,  // 64MB
    'time_cost' => 4,        // 4 iterations
    'threads' => 3,          // 3 threads
]);

// Password validation before hashing
function validatePassword(string $password): void
{
    if (mb_strlen($password) < 8) {
        throw new ValidationException(['password' => 'Minimum 8 characters']);
    }
    if (strlen($password) > 72) {
        // bcrypt truncates at 72 bytes - strlen counts bytes
        throw new ValidationException(['password' => 'Password too long']);
    }
}
```

## Why

- **Argon2id**: Memory-hard algorithm resistant to GPU/ASIC attacks
- **Automatic Salting**: `password_hash` generates a unique salt per password
- **Timing-Safe**: `password_verify` prevents timing attacks
- **Future-Proof**: `password_needs_rehash` upgrades hashes when algorithm changes
- **bcrypt Limit**: bcrypt truncates passwords at 72 bytes - validate max length
- **PASSWORD_DEFAULT**: Currently bcrypt, will change to best available algorithm
