<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Models\User;
use App\Support\Http\ApiErrorCode;
use Illuminate\Support\Facades\Gate;

it('answers an unknown /api/v1 path with the NOT_FOUND envelope', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertExactJson([
            'code' => 'NOT_FOUND',
            'message' => 'The requested resource was not found.',
        ]);
});

it('answers a missing token with the UNAUTHENTICATED envelope', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertExactJson([
            'code' => 'UNAUTHENTICATED',
            'message' => 'Authentication is required to access this resource.',
        ]);
});

it('answers a denied policy with the FORBIDDEN envelope', function () {
    [$company, $user] = tenant(User::ROLE_MEMBER);

    testApiRoute('get', '_probe/forbidden', function () use ($company) {
        Gate::authorize('delete', $company);

        return response()->json(['ok' => true]);
    }, ['auth:sanctum']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/_probe/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('answers an unverified address with the EMAIL_NOT_VERIFIED envelope', function () {
    [$company, $user] = tenant();
    $user->forceFill(['email_verified_at' => null])->save();

    testApiRoute('get', '_probe/verified', fn () => response()->json(['ok' => true]), [
        'auth:sanctum',
        EnsureEmailIsVerified::class,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/_probe/verified')
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/_probe/verified')->assertOk();
});

it('answers an unhandled exception with the SERVER_ERROR envelope', function () {
    config()->set('app.debug', false);

    testApiRoute('get', '_probe/boom', function () {
        throw new RuntimeException('internal detail that must not leak');
    });

    $response = $this->getJson('/api/v1/_probe/boom')->assertStatus(500);

    $response->assertExactJson([
        'code' => 'SERVER_ERROR',
        'message' => 'Something went wrong on our side.',
    ]);

    expect($response->getContent())->not->toContain('internal detail that must not leak');
});

it('surfaces the real message while debugging', function () {
    config()->set('app.debug', true);

    testApiRoute('get', '_probe/boom-debug', function () {
        throw new RuntimeException('developer facing detail');
    });

    $this->getJson('/api/v1/_probe/boom-debug')
        ->assertStatus(500)
        ->assertJsonPath('code', 'SERVER_ERROR')
        ->assertJsonPath('message', 'developer facing detail');
});

it('carries a Retry-After header on an ApiException that sets one', function () {
    testApiRoute('get', '_probe/quota', function () {
        throw new ApiException(ApiErrorCode::QuotaExceeded, retryAfter: 30);
    });

    $this->getJson('/api/v1/_probe/quota')
        ->assertStatus(402)
        ->assertJsonPath('code', 'QUOTA_EXCEEDED')
        ->assertHeader('Retry-After', '30');
});

it('maps an unlisted http status onto SERVER_ERROR without changing the status', function () {
    testApiRoute('get', '_probe/teapot', fn () => abort(418, 'I am a teapot'));

    $this->getJson('/api/v1/_probe/teapot')
        ->assertStatus(418)
        ->assertJsonPath('code', 'SERVER_ERROR');
});

it('leaves non-api responses alone', function () {
    $this->get('/does-not-exist-either')->assertStatus(404);

    $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
});

it('translates the message when the client asks for Turkish', function () {
    $this->withHeader('Accept-Language', 'tr')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Bu kaynağa erişmek için oturum açmanız gerekiyor.');
});

it('falls back to English for an unsupported language', function () {
    $this->withHeader('Accept-Language', 'de')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Authentication is required to access this resource.');
});

it('includes the field errors only for a validation failure', function () {
    $validation = $this->postJson('/api/v1/auth/login', [])->assertStatus(422);

    expect($validation->json())->toHaveKeys(['code', 'message', 'errors']);

    $notFound = $this->getJson('/api/v1/does-not-exist')->assertStatus(404);

    expect($notFound->json())->not->toHaveKey('errors');
});
