<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskPlanningRequest extends FormRequest
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
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'task_id.required' => 'Veuillez sélectionner une tâche.',
            'start_at.required' => 'La date et l’heure de début sont obligatoires.',
            'end_at.required' => 'La date et l’heure de fin sont obligatoires.',
            'end_at.after' => 'La fin doit être postérieure au début.',
        ];
    }
}
