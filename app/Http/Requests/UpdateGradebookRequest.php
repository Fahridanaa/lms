<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradebookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'score' => 'sometimes|numeric|min:0',
            'max_score' => 'sometimes|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'score.numeric' => 'Skor harus berupa angka.',
            'score.min' => 'Skor tidak boleh kurang dari 0.',
            'max_score.numeric' => 'Skor maksimum harus berupa angka.',
            'max_score.min' => 'Skor maksimum tidak boleh kurang dari 0.',
        ];
    }
}
