<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGlpiIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url:http,https', 'max:500'],
            'api_version' => ['required', Rule::in(['v1'])],
            'app_token' => ['nullable', 'string', 'max:1000'],
            'user_token' => ['nullable', 'string', 'max:1000'],
            'entity_id' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_url.required' => 'L’adresse de l’API GLPI est obligatoire.',
            'base_url.url' => 'L’adresse de l’API GLPI doit être une URL valide.',
        ];
    }
}
