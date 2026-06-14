<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkerGradeAssignmentRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'score' => 'required|numeric|min:0',
            'feedback' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'score.required' => 'Score is required.',
            'score.numeric' => 'Score must be a number.',
            'score.min' => 'Score must not be negative.',
            'feedback.string' => 'Feedback must be text.',
        ];
    }
}
