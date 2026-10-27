<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'nodes' => ['required', 'array', 'max:150'],
            'nodes.*.id' => ['required', 'string', 'max:100'],
            'nodes.*.type' => ['required', Rule::in(['start', 'end', 'task', 'approval', 'condition', 'notification'])],
            'nodes.*.label' => ['required', 'string', 'max:150'],
            'nodes.*.position' => ['required', 'array'],
            'nodes.*.position.x' => ['required', 'numeric', 'between:-100000,100000'],
            'nodes.*.position.y' => ['required', 'numeric', 'between:-100000,100000'],
            'nodes.*.data' => ['nullable', 'array'],
            'edges' => ['required', 'array', 'max:250'],
            'edges.*.id' => ['required', 'string', 'max:100'],
            'edges.*.source' => ['required', 'string', 'max:100'],
            'edges.*.target' => ['required', 'string', 'max:100'],
            'edges.*.sourceHandle' => ['nullable', 'string', 'max:100'],
            'edges.*.label' => ['nullable', 'string', 'max:150'],
        ];
    }
}
