<?php

namespace App\Support\Connectors;

/**
 * One channel OmniHear pulls feedback from.
 *
 * Every implementation fetches incrementally from an opaque cursor kept in
 * `integrations.sync_cursor`. A full re-scan on every sync is forbidden by
 * spec 6.1 — it is both a rate-limit and a cost problem, and on the App Store
 * feed it is not even possible (page depth is capped at 10).
 */
interface PlatformConnector
{
    /**
     * @param  string|null  $cursor  the value stored on the integration, or null on the first run
     *
     * @throws ConnectorException
     */
    public function fetchPage(?string $cursor): ConnectorPage;

    public function limits(): ConnectorLimits;

    public function healthCheck(): ConnectorHealth;
}
