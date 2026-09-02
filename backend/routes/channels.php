<?php

use App\Broadcasting\CompanyChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| One private channel per tenant (spec 6.5). FeedbackAnalyzed and
| QuotaThresholdReached are published on it; CompanyChannel decides who may
| listen. The authorization endpoint itself is registered in bootstrap/app.php
| under /api/v1 behind auth:sanctum.
|
| A user whose company_id does not match the channel segment is rejected - that
| is invariant I1 on the websocket surface, and it has its own test in
| tests/Feature/Analysis/BroadcastChannelAuthTest.php.
|
*/

Broadcast::channel('company.{companyId}', CompanyChannel::class);
