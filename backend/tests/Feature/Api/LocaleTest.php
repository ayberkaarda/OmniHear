<?php

/*
|--------------------------------------------------------------------------
| Accept-Language handling
|--------------------------------------------------------------------------
|
| Regression guard for middleware ordering: Laravel sorts route middleware by
| $middlewarePriority, which places Authenticate ahead of SubstituteBindings
| and therefore ahead of anything *appended* to the api group. With SetLocale
| appended, a 401 was rendered before the locale had been chosen and always
| came back in English. SetLocale is prepended for exactly this reason.
|
*/

it('resolves the locale from Accept-Language', function (?string $header, string $expected) {
    testApiRoute('get', '_probe/locale', fn () => response()->json(['locale' => app()->getLocale()]));

    $test = $header === null ? $this : $this->withHeader('Accept-Language', $header);

    $test->getJson('/api/v1/_probe/locale')
        ->assertOk()
        ->assertJsonPath('locale', $expected);
})->with([
    'no header' => [null, 'en'],
    'turkish' => ['tr', 'tr'],
    'english' => ['en', 'en'],
    'unsupported' => ['de', 'en'],
    'weighted' => ['de;q=0.9,tr;q=0.8', 'tr'],
]);

it('localises an error raised before the route is reached', function () {
    $this->withHeader('Accept-Language', 'tr')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Bu kaynağa erişmek için oturum açmanız gerekiyor.');
});

it('localises a validation error', function () {
    $this->withHeader('Accept-Language', 'tr')
        ->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Gönderilen veriler geçersiz.');
});
