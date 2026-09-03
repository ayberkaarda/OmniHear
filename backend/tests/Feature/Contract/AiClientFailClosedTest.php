<?php

use App\Jobs\AnalyzeFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiServiceUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiServiceFake;

/**
 * Invariant I7, from the client side: no shared secret means no request.
 *
 * `config/ai.php` falls back to the empty string when AI_SERVICE_HMAC_SECRET is
 * unset, and `hash_hmac()` will happily key on it - so a misconfigured backend
 * used to post customer feedback to whatever `ai.base_url` names, signed with a
 * key that is not a secret. The inbound webhook verifiers have always thrown in
 * the same situation (`signing_secret_not_configured`); this is the outbound
 * half catching up.
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }

    Http::preventStrayRequests();
});

it('refuses to call the analyzer when the shared secret is unset', function (string $secret) {
    config(['ai.hmac_secret' => $secret]);

    // preventStrayRequests turns "sent anyway" into a failure of its own, so
    // this asserts both that it threw and that nothing left the process.
    Http::fake();

    expect(fn () => app(AiClient::class)->analyze('anything', null, 'cid-1'))
        ->toThrow(AiServiceUnavailableException::class);

    Http::assertNothingSent();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('carries a readable reason rather than a bare 500', function () {
    config(['ai.hmac_secret' => '']);
    Http::fake();

    try {
        app(AiClient::class)->analyze('anything', null, 'cid-2');
    } catch (AiServiceUnavailableException $e) {
        expect($e->reason)->toBe('signing_secret_not_configured')
            ->and($e->status())->toBe(503);

        return;
    }

    $this->fail('AiClient sent a request with no signing secret.');
});

it('still signs and sends once a secret is configured', function () {
    config(['ai.hmac_secret' => 'a-real-shared-secret']);

    // The 200 body is a shape contracts/fixtures/analyze/ already covers, so
    // it comes from there rather than from inline JSON (CLAUDE.md section 2).
    Http::fake([
        '*/v1/analyze' => Http::response(AiServiceFake::successBody(), 200),
    ]);

    app(AiClient::class)->analyze('anything', null, 'cid-3');

    Http::assertSent(fn ($request): bool => $request->hasHeader(
        'X-Signature',
        hash_hmac('sha256', $request->body(), 'a-real-shared-secret'),
    ));
});

it('leaves the feedback pending and the quota unspent when the secret is missing', function () {
    config(['ai.hmac_secret' => '']);
    Http::fake();

    $company = Company::factory()->create(['quota_limit' => 10, 'analyzed_feedback_count' => 0]);
    $feedback = Feedback::factory()->for($company)->create();

    expect(fn () => (new AnalyzeFeedbackJob($company->id, $feedback->id))->handle(app(TenantContext::class)))
        ->toThrow(AiServiceUnavailableException::class);

    // A misconfiguration must cost neither the customer's quota nor the row:
    // the job is retried and eventually dead-lettered, and the feedback is
    // still waiting when somebody sets the variable.
    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_PENDING)
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(0);

    Http::assertNothingSent();
});
