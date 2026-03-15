<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DocumentUploadRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'max:10240', 'mimes:txt,md,csv,json,xml,html,yml,yaml,pdf,docx,pages,php,js,ts,py,log'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => 'Please upload at least one file.',
            'files.*.max' => 'Each file must be smaller than 10MB.',
            'files.*.mimes' => 'Unsupported file type. Supported: txt, md, csv, json, xml, html, yml, yaml, pdf, docx, pages, php, js, ts, py, log.',
        ];
    }
}
