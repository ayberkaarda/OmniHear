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
