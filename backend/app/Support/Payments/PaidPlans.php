<?php

namespace App\Support\Payments;

/**
 * Which plans can be bought.
 *
 * Derived from config/quota.php rather than listed here, so the plan catalogue
 * has exactly one home. `free` is what a company starts on and is the only
 * plan that is never sold.
 */
final class PaidPlans
{
    public const FREE = 'free';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $plans = config('quota.plans');

        if (! is_array($plans)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($plans),
            static fn (mixed $plan): bool => is_string($plan) && $plan !== self::FREE,
        ));
    }

    public static function isPaid(mixed $plan): bool
    {
        return is_string($plan) && in_array($plan, self::all(), strict: true);
    }
}
