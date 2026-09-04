<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/two-factor/confirm and
 * POST /api/v1/auth/two-factor/recovery-codes.
 *
 * Only the shape is checked here. Whether the six digits are *the* six digits
 * is not a validation question: a wrong-but-well-formed code is
 * TWO_FACTOR_CODE_INVALID, one catalogued code for every way a code can fail,
 * rather than a field-level message that would tell an attacker which part of
 * the attempt was close.
 */
class ConfirmTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->input('code'));
    }
}
