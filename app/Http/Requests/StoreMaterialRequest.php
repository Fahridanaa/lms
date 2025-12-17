<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
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
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'file_path' => 'required|string',
            'file_size' => 'required|integer',
            'type' => 'required|in:pdf,video,document,image,other',
        ];
    }

    public function messages(): array
    {
        return [
            'course_id.required' => 'ID kursus wajib diisi.',
            'course_id.exists' => 'Kursus tidak ditemukan.',
            'title.required' => 'Judul wajib diisi.',
            'title.string' => 'Judul harus berupa teks.',
            'title.max' => 'Judul tidak boleh lebih dari 255 karakter.',
            'file_path.required' => 'Path file wajib diisi.',
            'file_path.string' => 'Path file harus berupa teks.',
            'file_size.required' => 'Ukuran file wajib diisi.',
            'file_size.integer' => 'Ukuran file harus berupa angka bulat.',
            'type.required' => 'Tipe materi wajib diisi.',
            'type.in' => 'Tipe materi tidak valid.',
        ];
    }
}
