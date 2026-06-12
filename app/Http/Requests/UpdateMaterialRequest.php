<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
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
            'title' => 'sometimes|string|max:255',
            'file_size' => 'sometimes|integer',
            'type' => 'sometimes|in:pdf,video,document,image,other',
            'mime_type' => 'nullable|string|max:255',
            'checksum' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'title.string' => 'Judul harus berupa teks.',
            'title.max' => 'Judul tidak boleh lebih dari 255 karakter.',
            'type.in' => 'Tipe materi tidak valid.',
        ];
    }
}
