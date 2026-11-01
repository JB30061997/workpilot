<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ImportActionPlanRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['xlsx'])->max('10mb'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Sélectionnez un fichier Excel à importer.',
            'file.mimes' => 'Le fichier doit être au format Excel .xlsx.',
            'file.max' => 'Le fichier Excel ne peut pas dépasser 10 Mo.',
        ];
    }
}
