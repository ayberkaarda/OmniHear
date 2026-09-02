<?php

use App\Models\AiAnalysis;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\Scopes\CompanyScope;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same external comment is never stored twice
|--------------------------------------------------------------------------
*/

it('rejects a duplicate (integration_id, external_id) pair', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create();

    Feedback::factory()->for($company)->for($integration)->create(['external_id' => 'dup-1']);

    expect(fn () => Feedback::factory()->for($company)->for($integration)->create(['external_id' => 'dup-1']))
        ->toThrow(QueryException::class);
});

it('allows the same external_id under a different integration', function () {
    $company = Company::factory()->create();
    $first = Integration::factory()->for($company)->create();
    $second = Integration::factory()->for($company)->create();

    Feedback::factory()->for($company)->for($first)->create(['external_id' => 'shared']);
    Feedback::factory()->for($company)->for($second)->create(['external_id' => 'shared']);

    // tenant-scope: bypass-ok schema-level assertion; both rows belong to the same tenant anyway
    $all = Feedback::query()->withoutGlobalScope(CompanyScope::class)->count(); // tenant-scope: bypass-ok

    expect($all)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Invariant I3 — webhook replay protection
|--------------------------------------------------------------------------
*/

it('rejects a duplicate webhook event_id', function () {
    WebhookEvent::factory()->create(['event_id' => 'evt_dup']);

    expect(fn () => WebhookEvent::factory()->create(['event_id' => 'evt_dup']))
        ->toThrow(QueryException::class);
});

it('keeps webhook_events free of a company_id column', function () {
    expect(Schema::hasColumn('webhook_events', 'company_id'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Numeric check constraints on ai_analyses
|--------------------------------------------------------------------------
*/

it('rejects a sentiment score outside -1..1', function (float $score) {
    $company = Company::factory()->create();
    $feedback = Feedback::factory()->for($company)->create();

    expect(fn () => AiAnalysis::factory()
        ->for($company)
        ->for($feedback)
        ->create(['sentiment_score' => $score]))
        ->toThrow(QueryException::class);
})->with([1.5, -1.5]);

it('rejects a confidence outside 0..1', function (float $confidence) {
    $company = Company::factory()->create();
    $feedback = Feedback::factory()->for($company)->create();

    expect(fn () => AiAnalysis::factory()
        ->for($company)
        ->for($feedback)
        ->create(['confidence' => $confidence]))
        ->toThrow(QueryException::class);
})->with([1.5, -0.5]);

it('accepts the boundary values', function () {
    $company = Company::factory()->create();
    $feedback = Feedback::factory()->for($company)->create();

    $analysis = AiAnalysis::factory()->for($company)->for($feedback)->create([
        'sentiment_score' => -1,
        'confidence' => 1,
    ]);

    expect($analysis->sentiment_score)->toBe(-1.0)
        ->and($analysis->confidence)->toBe(1.0);
});

/*
|--------------------------------------------------------------------------
| Shape details the contract calls out explicitly
|--------------------------------------------------------------------------
*/

it('keeps audit_logs immutable by having no updated_at column', function () {
    expect(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

it('stores every timestamp with a time zone', function () {
    // tenant-scope: bypass-ok reading information_schema, which has no tenant
    $rows = DB::select(
        "select table_name, column_name, data_type
         from information_schema.columns
         where table_schema = 'public'
           and column_name in ('created_at','updated_at','published_at','analyzed_at','last_synced_at','email_verified_at','canceled_at','processed_at','current_period_start','current_period_end')
           and table_name in ('companies','users','subscriptions','integrations','feedbacks','ai_analyses','webhook_events','audit_logs')"
    );

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->data_type)->toBe('timestamp with time zone', "{$row->table_name}.{$row->column_name}");
    }
});

it('stores integration credentials in a text column so the encrypted cast fits', function () {
    // tenant-scope: bypass-ok reading information_schema, which has no tenant
    $type = DB::selectOne(
        "select data_type from information_schema.columns
         where table_schema = 'public' and table_name = 'integrations' and column_name = 'credentials'"
    );

    expect($type->data_type)->toBe('text');
});

it('seeds the free plan quota from config', function () {
    $company = Company::factory()->create();

    expect($company->quota_limit)->toBe((int) config('quota.plans.free.quota_limit'))
        ->and($company->quota_limit)->toBe(200);
});
