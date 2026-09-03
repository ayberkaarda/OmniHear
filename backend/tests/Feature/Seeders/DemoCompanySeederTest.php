<?php

use App\Models\AiAnalysis;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;
use Database\Seeders\DemoCompanySeeder;

use function Pest\Laravel\seed;

/**
 * The seeder is what makes the product runnable for somebody who has just
 * cloned the repository (ADR-0004), so it is worth a test: a broken seeder is
 * discovered by exactly the person it was written for.
 */
beforeEach(function () {
    seed(DemoCompanySeeder::class);

    $this->company = Company::query()->where('name', DemoCompanySeeder::COMPANY_NAME)->firstOrFail();
});

it('creates one company with all three roles', function () {
    $roles = $this->company->users()->orderBy('id')->pluck('role', 'email')->all();

    expect($roles)->toBe([
        DemoCompanySeeder::OWNER_EMAIL => User::ROLE_OWNER,
        DemoCompanySeeder::ADMIN_EMAIL => User::ROLE_ADMIN,
        DemoCompanySeeder::MEMBER_EMAIL => User::ROLE_MEMBER,
    ]);
});

it('leaves every demo user able to sign in and reach the tenant surface', function () {
    $owner = $this->company->users()->where('email', DemoCompanySeeder::OWNER_EMAIL)->firstOrFail();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => DemoCompanySeeder::OWNER_EMAIL,
        'password' => DemoCompanySeeder::PASSWORD,
    ])->assertOk()->json('token');

    expect($owner->email_verified_at)->not->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/overview/kpis')
        ->assertOk();
});

it('sets a quota low enough to demonstrate the paywall', function () {
    expect($this->company->quota_limit)->toBe(DemoCompanySeeder::QUOTA_LIMIT)
        ->and($this->company->quota_limit)->toBeLessThan((int) config('quota.plans.free.quota_limit'))
        ->and($this->company->analyzed_feedback_count)->toBe(DemoCompanySeeder::FEEDBACK_COUNT)
        ->and($this->company->quotaRemaining())->toBe(
            DemoCompanySeeder::QUOTA_LIMIT - DemoCompanySeeder::FEEDBACK_COUNT
        );
});

it('starts already past the soft warning threshold', function () {
    $used = $this->company->analyzed_feedback_count / $this->company->quota_limit;

    expect($used)->toBeGreaterThanOrEqual((float) config('quota.warning_threshold'));
});

it('creates one credential-free integration', function () {
    $integrations = asTenant($this->company, fn () => Integration::query()->get());

    expect($integrations)->toHaveCount(1)
        ->and($integrations->first()->platform)->toBe('fixture')
        ->and($integrations->first()->status)->toBe('active')
        ->and($integrations->first()->credentials)->toBe([]);
});

it('creates analysed feedback across every sentiment and every category', function () {
    [$feedbacks, $analyses] = asTenant($this->company, fn (): array => [
        Feedback::query()->get(),
        AiAnalysis::query()->get(),
    ]);

    expect($feedbacks)->toHaveCount(DemoCompanySeeder::FEEDBACK_COUNT)
        ->and($analyses)->toHaveCount(DemoCompanySeeder::FEEDBACK_COUNT)
        ->and($feedbacks->pluck('analysis_status')->unique()->all())->toBe([Feedback::STATUS_ANALYZED])
        ->and($analyses->pluck('sentiment_label')->unique()->sort()->values()->all())
        ->toBe(['negative', 'neutral', 'positive'])
        ->and($analyses->pluck('category')->unique()->values()->all())
        ->toEqualCanonicalizing(AiAnalysis::CATEGORIES);
});

it('keeps every generated analysis inside the contract bounds', function () {
    $analyses = asTenant($this->company, fn () => AiAnalysis::query()->get());

    foreach ($analyses as $analysis) {
        expect($analysis->sentiment_score)->toBeGreaterThanOrEqual(-1.0)
            ->and($analysis->sentiment_score)->toBeLessThanOrEqual(1.0)
            ->and($analysis->confidence)->toBeGreaterThanOrEqual(0.0)
            ->and($analysis->confidence)->toBeLessThanOrEqual(1.0)
            ->and($analysis->keywords)->not->toBeEmpty();
    }
});

it('files every row under the demo tenant and nowhere else', function () {
    $other = Company::factory()->create();

    expect(asTenant($other, fn () => Feedback::query()->count()))->toBe(0)
        ->and(asTenant($other, fn () => Integration::query()->count()))->toBe(0)
        ->and(asTenant($this->company, fn () => Feedback::query()->count()))
        ->toBe(DemoCompanySeeder::FEEDBACK_COUNT);
});

it('refuses to double the data when it is run twice', function () {
    seed(DemoCompanySeeder::class);

    expect(Company::query()->where('name', DemoCompanySeeder::COMPANY_NAME)->count())->toBe(1)
        ->and(asTenant($this->company, fn () => Feedback::query()->count()))
        ->toBe(DemoCompanySeeder::FEEDBACK_COUNT);
});

it('is not wired into the default seeder', function () {
    // DatabaseSeeder stays Laravel's own test@example.com: `php artisan
    // db:seed` with no arguments must not invent a tenant.
    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

    expect($source)->not->toContain('DemoCompanySeeder');
});
