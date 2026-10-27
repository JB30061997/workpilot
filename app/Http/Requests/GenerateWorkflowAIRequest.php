<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateWorkflowAIRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'mode' => ['required', Rule::in(['new', 'improve'])],
            'current_graph' => ['nullable', 'required_if:mode,improve', 'array'],
            'current_graph.nodes' => ['required_if:mode,improve', 'array', 'max:40'],
            'current_graph.nodes.*.id' => ['required', 'string', 'max:60'],
            'current_graph.nodes.*.type' => ['required', Rule::in(['start', 'task', 'approval', 'condition', 'notification', 'end'])],
            'current_graph.nodes.*.label' => ['required', 'string', 'max:150'],
            'current_graph.nodes.*.description' => ['nullable', 'string', 'max:500'],
            'current_graph.edges' => ['required_if:mode,improve', 'array', 'max:80'],
            'current_graph.edges.*.source' => ['required', 'string', 'max:60'],
            'current_graph.edges.*.target' => ['required', 'string', 'max:60'],
            'current_graph.edges.*.label' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Décrivez le processus avec au moins 20 caractères.',
            'description.max' => 'La description ne peut pas dépasser 5 000 caractères.',
        ];
    }
}
