<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Support\DisposableEmailDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     *
     * Creates the company and its first (owner) user in one transaction.
     */
    public function register(RegisterRequest $request, DisposableEmailDomains $disposable): JsonResponse
    {
        $email = (string) $request->string('email');

        if ($disposable->blocks($email)) {
            throw ApiException::disposableEmail();
        }

        /** @var array{company: Company, user: User} $created */
        $created = DB::transaction(function () use ($request, $email): array {
            $company = Company::create([
                'name' => (string) $request->string('company_name'),
                'plan' => 'free',
                'quota_limit' => (int) config('quota.plans.free.quota_limit'),
            ]);

            $user = User::create([
                'company_id' => $company->id,
                'name' => (string) $request->string('name'),
                'email' => $email,
                'password' => (string) $request->string('password'),
                'role' => User::ROLE_OWNER,
            ]);

            return ['company' => $company, 'user' => $user];
        });

        // The Laravel 11+ skeleton registers no listener for the Registered
        // event, so the verification mail is sent explicitly here.
        $created['user']->sendEmailVerificationNotification();

        return response()->json([
            'token' => $created['user']->createToken('web')->plainTextToken,
            'user' => new UserResource($created['user']),
            'company' => new CompanyResource($created['company']),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/auth/login
     *
     * An unknown e-mail and a wrong password produce the same code and roughly
     * the same timing, so the endpoint cannot be used to enumerate accounts.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->string('email'))->first();

        if ($user === null) {
            // Burn a comparable amount of time before failing.
            Hash::make((string) $request->string('password'));

            throw ApiException::invalidCredentials();
        }

        if (! Hash::check((string) $request->string('password'), $user->getAuthPassword())) {
            throw ApiException::invalidCredentials();
        }

        $user->forceFill(['last_login_ip' => $request->ip()])->save();

        return response()->json([
            'token' => $user->createToken($request->deviceName())->plainTextToken,
            'user' => new UserResource($user),
            'company' => new CompanyResource($user->company),
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revokes the current token only.
     */
    public function logout(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'company' => new CompanyResource($user->company),
        ]);
    }
}
