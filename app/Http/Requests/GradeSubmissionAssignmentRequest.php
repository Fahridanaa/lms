<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionAssignmentRequest extends FormRequest
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
            'score.required' => 'Skor wajib diisi.',
            'score.numeric' => 'Skor harus berupa angka.',
            'score.min' => 'Skor tidak boleh kurang dari 0.',
            'feedback.string' => 'Umpan balik harus berupa teks.',
        ];
    }
}
