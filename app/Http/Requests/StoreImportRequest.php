<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An import upload: the source file plus the run options. Booleans arrive as multipart fields, so `1`/`0`
 * (or `true`/`false` in JSON) are accepted.
 */
class StoreImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = max(1, config()->integer('intrawatch.import.max_upload_mb', 256)) * 1024;

        return [
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
            'history' => ['sometimes', 'boolean'],
            'paused' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The file is larger than the upload limit of '.config()->integer('intrawatch.import.max_upload_mb', 256)
                .' MB. Import larger databases with the command-line importer.',
        ];
    }
}
