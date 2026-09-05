/**
 * Wire shapes for the auth surface of the Laravel API.
 *
 * Every field here comes from `docs/contracts/http-api-v1.md` sections 4 and 5.
 * Nothing is invented: snake_case is kept exactly as the API serialises it so a
 * response can be assigned without a translation layer.
 */

export type UserRole = 'owner' | 'admin' | 'member';
export type CompanyPlan = 'free' | 'pro';

export interface User {
  id: number;
  company_id: number;
  name: string;
  email: string;
  role: UserRole;
  email_verified_at: string | null;
  two_factor_enabled: boolean;
  created_at: string;
}

export interface Company {
  id: number;
  name: string;
  plan: CompanyPlan;
  analyzed_feedback_count: number;
  quota_limit: number;
  quota_remaining: number;
  created_at: string;
}

/** `POST /auth/register` and `POST /auth/login` both return this envelope. */
export interface AuthSessionResponse {
  token: string;
  user: User;
  company: Company;
}

/** `GET /auth/me`. */
export interface SessionResponse {
  user: User;
  company: Company;
}

/** `POST /auth/email/verify`. */
export interface VerifyEmailResponse {
  user: User;
}

/** `POST /auth/forgot-password` (always 202, never leaks account existence). */
export interface MessageResponse {
  message: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  company_name: string;
}

export interface LoginRequest {
  email: string;
  password: string;
  device_name?: string;
}

export interface ForgotPasswordRequest {
  email: string;
}

export interface ResetPasswordRequest {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface VerifyEmailRequest {
  id: number;
  hash: string;
  expires: number;
  signature: string;
}

/**
 * `GET /api/v1/invitations/{token}` — everything a token-holder may learn
 * about the tenant that invited them (`docs/contracts/settings-api.md` 3a).
 *
 * The company's *name* and nothing else: no id, no plan, no counts. An expired,
 * already-accepted or unknown token answers 404 alike, so there is no state
 * here for "expired" to be distinguished from "never existed".
 */
export interface PendingInvitation {
  email: string;
  company_name: string;
  role: UserRole;
  expires_at: string;
}

export interface InvitationResponse {
  invitation: PendingInvitation;
}

/**
 * `POST /api/v1/invitations/{token}/accept`.
 *
 * No `email`: the address is fixed by the invitation row. Sending one would be
 * ignored, and offering the field would suggest it is not.
 */
export interface AcceptInvitationRequest {
  name: string;
  password: string;
  password_confirmation: string;
}

/* -------------------------------------------------------------------------- */
/* Two-factor authentication — `docs/contracts/w10-two-factor.md`             */
/* -------------------------------------------------------------------------- */

/**
 * `POST /auth/login` when the password was right and a second factor is owed.
 *
 * **200, not 401.** This is a successful first factor, not a failure: the error
 * interceptor maps 401 to `UNAUTHENTICATED` and tears the session down, so a
 * 401 here would sign the user out of the flow they are entering.
 */
export interface TwoFactorChallengeResponse {
  two_factor_required: true;
  challenge_token: string;
}

/** What `POST /auth/login` may now answer — one of two success shapes. */
export type LoginResponse = AuthSessionResponse | TwoFactorChallengeResponse;

/**
 * Discriminates the two 200s. Tests the literal flag rather than the absence of
 * `token`: a body that carried neither would otherwise be read as a session.
 */
export function isTwoFactorChallenge(response: LoginResponse): response is TwoFactorChallengeResponse {
  return (response as TwoFactorChallengeResponse).two_factor_required === true;
}

/**
 * `POST /auth/two-factor/challenge` — exactly one of the two fields, never both.
 * The union is what stops a caller from sending an empty object or a pair.
 */
export type TwoFactorChallengeRequest = { code: string } | { recovery_code: string };

/**
 * `POST /auth/two-factor` request. The password re-proves the session before a
 * second factor is armed: arming one an attacker holds is as durable a takeover
 * as removing one the owner holds, so a valid session is deliberately not enough.
 */
export interface TwoFactorEnrolmentRequest {
  password: string;
}

/** `POST /auth/two-factor` (201). The secret is served here and nowhere else. */
export interface TwoFactorEnrolmentResponse {
  secret: string;
  otpauth_url: string;
  /** `data:image/svg+xml;base64,…` — rendered server-side, straight into `<img src>`. */
  qr_svg_data_uri: string;
}

/** `POST /auth/two-factor/confirm` and `POST /auth/two-factor/recovery-codes`. */
export interface RecoveryCodesResponse {
  recovery_codes: string[];
}

export interface TwoFactorCodeRequest {
  code: string;
}

/** `DELETE /auth/two-factor` — both factors re-proved before one is removed. */
export interface TwoFactorDisableRequest {
  password: string;
  code: string;
}
