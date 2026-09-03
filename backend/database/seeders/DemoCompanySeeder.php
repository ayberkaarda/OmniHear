<?php

namespace Database\Seeders;

use App\Models\AiAnalysis;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A tenant somebody can log into thirty seconds after cloning the repository.
 *
 * ADR-0004 assumes a reviewer who checks the repository out and runs it; an
 * empty database gives them a dashboard of zeroes, an inbox with nothing in it
 * and no way to see the paywall without waiting for two hundred analyses.
 *
 *   php artisan db:seed --class=DemoCompanySeeder
 *
 * # What it builds
 *
 * - one company on the free plan with a **deliberately low quota**
 *   (self::QUOTA_LIMIT), already close to exhaustion, so the 80% warning and
 *   the 402 paywall are both reachable immediately;
 * - an owner, an admin and a member, so every role in the settings screens can
 *   be exercised without editing rows by hand;
 * - one `fixture` integration, which is the only platform that needs no
 *   credentials and no network;
 * - self::FEEDBACK_COUNT analysed feedback rows spread across every sentiment
 *   label and every category, dated over the last month so the trend charts
 *   have a shape rather than a spike.
 *
 * # Why the numbers are not random
 *
 * A faker-driven distribution makes a screenshot that differs on every run and
 * a KPI panel nobody can review. The rows here are generated from a fixed
 * rotation and a fixed clock offset, so two people running this see the same
 * dashboard.
 *
 * Idempotent: it refuses to run twice for the same company rather than
 * doubling the data, because re-running a seeder is what a reviewer does when
 * they are unsure whether the first one worked.
 */
class DemoCompanySeeder extends Seeder
{
    public const COMPANY_NAME = 'OmniHear Demo';

    public const OWNER_EMAIL = 'owner@omnihear.demo';

    public const ADMIN_EMAIL = 'admin@omnihear.demo';

    public const MEMBER_EMAIL = 'member@omnihear.demo';

    public const PASSWORD = 'demo-password-2026';

    /**
     * Low on purpose. The free plan is 200 (config/quota.php); demonstrating
     * the paywall at that number means ingesting two hundred analyses first,
     * which nobody reviewing the repository is going to do. 75 leaves the
     * counter at 80% after the rows below, so the soft warning has already
     * fired and the paywall is a handful of analyses away.
     */
    public const QUOTA_LIMIT = 75;

    public const FEEDBACK_COUNT = 60;

    /**
     * Sentiment label, category, and a body that matches both — a "negative /
     * praise" row would make the inbox look broken.
     *
     * @var list<array{0: string, 1: string, 2: float, 3: string}>
     */
    private const SAMPLES = [
        ['negative', 'bug', -0.82, 'The app crashes every time I open the notifications tab.'],
        ['negative', 'complaint', -0.64, 'Support took four days to answer and then closed the ticket.'],
        ['neutral', 'feature_request', 0.05, 'It would help to be able to export the weekly summary as CSV.'],
        ['positive', 'praise', 0.91, 'The new dashboard is genuinely fast. Whatever you changed, keep it.'],
        ['negative', 'complaint', -0.47, 'Billing charged me twice this month and I cannot find an invoice.'],
        ['positive', 'praise', 0.73, 'Setup took two minutes and it picked up our reviews straight away.'],
        ['neutral', 'feature_request', -0.02, 'Please add a dark theme, the light one is hard on night shifts.'],
        ['negative', 'bug', -0.71, 'Sync stops after the first page for our Zendesk account.'],
        ['positive', 'praise', 0.66, 'The sentiment scores match what our team reads in the tickets.'],
        ['neutral', 'complaint', -0.18, 'The mobile layout is usable but the filters are hard to reach.'],
        ['positive', 'feature_request', 0.34, 'Love it. A Slack digest would make it perfect.'],
        ['negative', 'bug', -0.88, 'Login loops back to the form after the verification e-mail.'],
    ];

    public function run(): void
    {
        $existing = Company::query()->where('name', self::COMPANY_NAME)->first();

        if ($existing !== null) {
            $this->command?->warn(
                'DemoCompanySeeder: "'.self::COMPANY_NAME.'" already exists (id '.$existing->id.'); nothing was changed.'
            );

            return;
        }

        $company = DB::transaction(fn (): Company => $this->build());

        $this->command?->info(
            'DemoCompanySeeder: company '.$company->id.', sign in as '.self::OWNER_EMAIL.' / '.self::PASSWORD.'. '
            .'Quota '.$company->analyzed_feedback_count.'/'.$company->quota_limit.'.'
        );
    }

    private function build(): Company
    {
        $company = new Company([
            'name' => self::COMPANY_NAME,
            'plan' => 'free',
            'quota_limit' => self::QUOTA_LIMIT,
        ]);

        // Assigned directly rather than through the constructor array: the
        // counter is deliberately outside $fillable, and the column default is
        // applied by the database and never read back into the model — the
        // same trap AuthController::register documents.
        $company->analyzed_feedback_count = 0;
        $company->save();

        foreach ([
            [self::OWNER_EMAIL, 'Demo Owner', User::ROLE_OWNER],
            [self::ADMIN_EMAIL, 'Demo Admin', User::ROLE_ADMIN],
            [self::MEMBER_EMAIL, 'Demo Member', User::ROLE_MEMBER],
        ] as [$email, $name, $role]) {
            User::query()->create([
                'company_id' => $company->id,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(self::PASSWORD),
                'role' => $role,
            ])->forceFill(['email_verified_at' => now()])->save();
        }

        // Everything below is tenant-scoped, so it runs inside the tenant
        // context rather than setting company_id by hand — the same path the
        // application itself uses (BelongsToCompany).
        app(TenantContext::class)->runFor($company->id, function (): void {
            $integration = Integration::query()->create([
                'platform' => 'fixture',
                'settings' => ['locale' => 'en', 'fixture_set' => 'default'],
                'credentials' => [],
                'status' => 'active',
                'last_synced_at' => now()->subMinutes(12),
            ]);

            $this->seedFeedback($integration);
        });

        // Set once at the end rather than incremented per row: QuotaCounter is
        // the only thing that may move this counter during a request, and a
        // seeder racing it would be a strange thing to model. The value is the
        // number of analyses that exist, which is what spec 7.2 counts.
        $company->forceFill(['analyzed_feedback_count' => self::FEEDBACK_COUNT])->save();

        return $company;
    }

    private function seedFeedback(Integration $integration): void
    {
        $samples = self::SAMPLES;

        for ($index = 0; $index < self::FEEDBACK_COUNT; $index++) {
            [$label, $category, $score, $body] = $samples[$index % count($samples)];

            // Spread backwards over roughly a month, newest first, with a
            // deterministic step so the trend chart is reproducible.
            $publishedAt = now()->subHours($index * 12)->startOfHour();

            $feedback = Feedback::query()->create([
                'integration_id' => $integration->id,
                'external_id' => 'demo-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'author' => 'Demo Reviewer '.(($index % 17) + 1),
                'body' => $body,
                'source_url' => 'https://example.test/reviews/demo-'.$index,
                'published_at' => $publishedAt,
                'raw_payload' => ['source' => 'DemoCompanySeeder'],
                'analysis_status' => Feedback::STATUS_ANALYZED,
            ]);

            AiAnalysis::query()->create([
                'feedback_id' => $feedback->id,
                'sentiment_score' => $score,
                'sentiment_label' => $label,
                'category' => $category,
                // Deterministic and inside the contract bounds (0..1).
                'confidence' => round(0.72 + (($index % 7) * 0.04), 2),
                'keywords' => $this->keywords($category),
                'model_version' => 'stub-0.1.0',
                'analyzed_at' => $publishedAt->copy()->addMinutes(3),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function keywords(string $category): array
    {
        return match ($category) {
            'bug' => ['crash', 'sync', 'login'],
            'complaint' => ['support', 'billing', 'delay'],
            'feature_request' => ['export', 'dark-mode', 'slack'],
            default => ['speed', 'setup', 'accuracy'],
        };
    }
}
