---
title: Lazy Loading
impact: MEDIUM
impactDescription: Defer expensive operations until actually needed to save memory and time
tags: performance, lazy-loading, optimization, php8
---

# Lazy Loading

Load resources and perform expensive operations only when they are actually needed, not at construction time.

## Bad Example

```php
<?php

declare(strict_types=1);

class ReportService
{
    private array $allUsers;
    private array $allOrders;
    private PDFGenerator $pdf;

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly OrderRepository $orderRepo,
    ) {
        // Loads everything upfront even if not all methods are called
        $this->allUsers = $this->userRepo->findAll();
        $this->allOrders = $this->orderRepo->findAll();
        $this->pdf = new PDFGenerator(); // Expensive initialization
    }

    public function getUserCount(): int
    {
        return count($this->allUsers);
    }

    public function generateReport(): string
    {
        return $this->pdf->generate($this->allOrders);
    }
}

// Just calling getUserCount() loads ALL orders and initializes PDF engine
$report = new ReportService($userRepo, $orderRepo);
echo $report->getUserCount();
```

## Good Example

```php
<?php

declare(strict_types=1);

class ReportService
{
    private ?array $users = null;
    private ?PDFGenerator $pdf = null;

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly OrderRepository $orderRepo,
    ) {}

    public function getUserCount(): int
    {
        return $this->userRepo->count(); // Query only what's needed
    }

    public function generateReport(): string
    {
        $orders = $this->orderRepo->findAll(); // Load when needed
        return $this->getPdf()->generate($orders);
    }

    private function getPdf(): PDFGenerator
    {
        // Lazy initialization - created only on first use
        return $this->pdf ??= new PDFGenerator();
    }

    /** @return array<User> */
    private function getUsers(): array
    {
        return $this->users ??= $this->userRepo->findAll();
    }
}

// getUserCount() only runs a COUNT query - no data loaded
$report = new ReportService($userRepo, $orderRepo);
echo $report->getUserCount();
```

## Why

- **Faster Startup**: Constructor does minimal work
- **Less Memory**: Only loads data that's actually used
- **Null Coalescing Assignment**: `??=` provides clean lazy init pattern
- **Query Optimization**: Use COUNT queries instead of loading all records
- **Pay for What You Use**: Expensive resources created only when needed
