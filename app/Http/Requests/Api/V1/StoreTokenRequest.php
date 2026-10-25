<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * The token request of the app authorisation (RFC 6749, 4.1.3, with the PKCE verifier of RFC 7636, 4.5). device_name
 * names the token in the profile ("Verbundene Apps"). Errors are answered the OAuth way: 400 with an error code.
 */
class StoreTokenRequest extends FormRequest
{
    /**
     * An OAuth error answer (RFC 6749, 5.2): `error` for the app, the text for the person, as `message` like every
     * other answer of the API and as `error_description` for OAuth libraries.
     */
    public static function error(string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message, 'error_description' => $message], 400, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'grant_type' => ['required', 'string', Rule::in(['authorization_code'])],
            'code' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:100'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9._~-]{43,128}$/'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(self::error('invalid_request', __('Die Anfrage ist unvollständig oder fehlerhaft: :fields.', ['fields' => implode(', ', $validator->errors()->keys())])));
    }
}
