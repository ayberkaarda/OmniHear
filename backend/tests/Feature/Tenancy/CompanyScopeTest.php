<?php

use App\Exceptions\MissingTenantContextException;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\Scopes\CompanyScope;
use App\Models\Subscription;
use App\Support\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| Invariant I1 — every query is tenant-scoped
|--------------------------------------------------------------------------
*/

$models = [
    Subscription::class,
    Integration::class,
    Feedback::class,
    AiAnalysis::class,
    AuditLog::class,
];

it('throws when a scoped model is queried with no tenant in context', function (string $model) {
    expect(app(TenantContext::class)->has())->toBeFalse();

    $model::query()->get();
})->with($models)->throws(MissingTenantContextException::class);

it('hides another tenant row from find()', function (string $model) {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $ofB = $model::factory()->for($companyB)->create();

    $found = asTenant($companyA, fn () => $model::query()->find($ofB->id));

    expect($found)->toBeNull();
})->with($models);

it('hides another tenant row from a listing', function (string $model) {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $ofA = $model::factory()->for($companyA)->create();
    $ofB = $model::factory()->for($companyB)->create();

    $ids = asTenant($companyA, fn () => $model::query()->pluck('id')->all());

    expect($ids)->toContain($ofA->id)
        ->and($ids)->not->toContain($ofB->id);
})->with($models);

it('counts only the rows of the tenant in context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Feedback::factory()->count(3)->for($companyA)->create();
    Feedback::factory()->count(5)->for($companyB)->create();

    expect(asTenant($companyA, fn () => Feedback::query()->count()))->toBe(3)
        ->and(asTenant($companyB, fn () => Feedback::query()->count()))->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Write side
|--------------------------------------------------------------------------
*/

it('stamps company_id from the tenant context on create', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create();

    $feedback = asTenant($company, fn () => Feedback::create([
        'integration_id' => $integration->id,
        'external_id' => 'ext-1',
        'body' => 'It crashes on launch.',
        'raw_payload' => ['source' => 'test'],
    ]));

    expect($feedback->company_id)->toBe($company->id);
});

it('ignores a company_id supplied in the payload', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $integration = Integration::factory()->for($companyA)->create();

    $feedback = asTenant($companyA, fn () => Feedback::create([
        'company_id' => $companyB->id,
        'integration_id' => $integration->id,
        'external_id' => 'ext-2',
        'body' => 'Mass assignment attempt.',
        'raw_payload' => [],
    ]));

    expect($feedback->company_id)->toBe($companyA->id);

    $this->assertDatabaseHas('feedbacks', ['external_id' => 'ext-2', 'company_id' => $companyA->id]);
    $this->assertDatabaseMissing('feedbacks', ['external_id' => 'ext-2', 'company_id' => $companyB->id]);
});

it('keeps an explicitly provided company_id when one is passed to the model directly', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create();

    $feedback = new Feedback([
        'integration_id' => $integration->id,
        'external_id' => 'ext-3',
        'body' => 'Direct assignment.',
        'raw_payload' => [],
    ]);
    $feedback->company_id = $company->id;
    $feedback->save();

    $reloaded = asTenant($company, fn () => Feedback::query()->findOrFail($feedback->id));

    expect($reloaded->getAttribute('company_id'))->toBe($company->id);
});

/*
|--------------------------------------------------------------------------
| Escape hatch
|--------------------------------------------------------------------------
*/

it('returns every tenant row only when the scope is explicitly removed', function () {
    Feedback::factory()->count(2)->create();

    // proving the documented escape hatch still works
    $all = Feedback::query()->withoutGlobalScope(CompanyScope::class)->count(); // tenant-scope: bypass-ok

    expect($all)->toBe(2);
});
