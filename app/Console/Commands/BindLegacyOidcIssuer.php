<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BindLegacyOidcIssuer extends Command
{
    protected $signature = 'oidc:bind-legacy-issuer
        {--issuer= : Exact original issuer, matching OIDC_EXPECTED_ISSUER}
        {--dry-run : Check the migration without changing bindings}';

    protected $description = 'Explicitly bind legacy subject-only SSO accounts to their original issuer';

    public function handle(): int
    {
        $issuer = $this->option('issuer');
        if (! is_string($issuer) || $issuer === '' || strlen($issuer) > 255
            || $issuer !== config('services.oidc.expected_issuer')) {
            $this->error('Provide --issuer with the exact original issuer, matching OIDC_EXPECTED_ISSUER.');

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($issuer): int {
                $legacy = User::query()->whereNotNull('oidc_id')->whereNull('oidc_issuer')->lockForUpdate()->get();
                $conflict = $legacy->groupBy('oidc_id')->contains(fn ($users): bool => $users->count() > 1)
                    || $legacy->contains(fn (User $user): bool => trim($user->oidc_id) === '')
                    || User::query()->where('oidc_issuer', $issuer)->whereIn('oidc_id', $legacy->pluck('oidc_id'))->exists();

                if ($conflict) {
                    $this->error('Conflicting legacy identities found. No bindings changed.');

                    return self::FAILURE;
                }

                if (! $this->option('dry-run')) {
                    User::query()->whereKey($legacy->modelKeys())->update(['oidc_issuer' => $issuer]);
                }

                $this->info(($this->option('dry-run') ? 'Would bind ' : 'Bound ').$legacy->count().' legacy SSO account(s).');

                return self::SUCCESS;
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('A binding changed concurrently. No legacy bindings changed; retry after reviewing conflicts.');

            return self::FAILURE;
        }
    }
}
