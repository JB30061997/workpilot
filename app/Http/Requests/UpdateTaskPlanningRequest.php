<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskPlanningRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_at.required' => 'La date et l’heure de début sont obligatoires.',
            'end_at.required' => 'La date et l’heure de fin sont obligatoires.',
            'end_at.after' => 'La fin doit être postérieure au début.',
        ];
    }
}
