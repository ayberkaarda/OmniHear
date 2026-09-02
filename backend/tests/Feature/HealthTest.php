<?php

it('returns a successful health check payload', function () {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJson(fn ($json) => $json
            ->where('status', 'ok')
            ->where('service', 'backend')
            ->has('time')
        );
});
