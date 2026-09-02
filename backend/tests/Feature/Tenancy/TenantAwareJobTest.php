<?php

use App\Jobs\TenantAwareJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Support\Tenancy\TenantContext;

class RecordingTenantJob extends TenantAwareJob
{
    /** @var list<int|null> */
    public static array $seen = [];

    public static int $rows = 0;

    protected function handleForTenant(): void
    {
        self::$seen[] = app(TenantContext::class)->id();
        self::$rows = Feedback::query()->count();
    }
}

class FailingTenantJob extends TenantAwareJob
{
    protected function handleForTenant(): void
    {
        throw new RuntimeException('job blew up');
    }
}

beforeEach(function () {
    RecordingTenantJob::$seen = [];
    RecordingTenantJob::$rows = 0;
});

it('establishes the tenant for the duration of the job', function () {
    $company = Company::factory()->create();
    Feedback::factory()->count(2)->for($company)->create();
    Feedback::factory()->count(3)->create();

    dispatch_sync(new RecordingTenantJob($company->id));

    expect(RecordingTenantJob::$seen)->toBe([$company->id])
        ->and(RecordingTenantJob::$rows)->toBe(2);
});

it('leaves no tenant behind after the job finishes', function () {
    $company = Company::factory()->create();

    dispatch_sync(new RecordingTenantJob($company->id));

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('leaves no tenant behind when the job throws', function () {
    $company = Company::factory()->create();

    expect(fn () => dispatch_sync(new FailingTenantJob($company->id)))
        ->toThrow(RuntimeException::class);

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('does not inherit the tenant of a previous job', function () {
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    dispatch_sync(new RecordingTenantJob($first->id));
    dispatch_sync(new RecordingTenantJob($second->id));

    expect(RecordingTenantJob::$seen)->toBe([$first->id, $second->id]);
});
