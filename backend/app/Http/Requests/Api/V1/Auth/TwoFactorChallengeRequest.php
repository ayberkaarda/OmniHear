<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/two-factor/challenge.
 *
 * Exactly one of `code` and `recovery_code`, which takes both halves:
 * `required_without` makes an empty body a 422 instead of a silent
 * TWO_FACTOR_CODE_INVALID, and `prohibits` refuses a body carrying both — a
 * caller that sends the two at once would otherwise get two attempts charged as
 * one against the token's budget.
 *
 * The recovery code is bounded but not pattern-matched. Its own format is
 * `xxxx-xxxx` (RecoveryCodes), and rejecting anything else here would answer a
 * malformed guess differently from a well-formed one — which is a free oracle
 * on the format for anyone who has not seen a real code.
 */
class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'string', 'max:64'],
        ];
    }

    public function totpCode(): ?string
    {
        $code = $this->input('code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    public function recoveryCode(): ?string
    {
        $code = $this->input('recovery_code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }
}
