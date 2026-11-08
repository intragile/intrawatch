<?php

namespace App\Http\Requests;

use App\Support\ImportUploadLimit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An import upload: the source file plus the run options. Booleans arrive as multipart fields, so `1`/`0`
 * (or `true`/`false` in JSON) are accepted.
 *
 * The upload limit is IMPORT_MAX_UPLOAD_MB, clamped to PHP's upload_max_filesize and post_max_size: PHP drops
 * a larger file before the application sees it, so the messages name the smaller of the two.
 */
class StoreImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `max` keeps the configured limit, so the published API description does not depend on the php.ini
        // of the machine that generated it; the closure applies the PHP limit.
        $configuredKilobytes = ImportUploadLimit::configuredMegabytes() * 1024;

        return [
            'file' => ['bail', 'required', 'file', 'max:'.$configuredKilobytes, $this->phpLimitRule()],
            'history' => ['sometimes', 'boolean'],
            'paused' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooLarge = 'The file is larger than the upload limit of '.$this->limitLabel().'. Import larger databases with the command-line importer.';

        return [
            'file.max' => $tooLarge,
            // PHP rejected the upload; with no other cause in sight, its size limit is the likely one.
            'file.uploaded' => 'The file failed to upload. Files larger than the upload limit of '.$this->limitLabel()
                .' are rejected; import larger databases with the command-line importer.',
        ];
    }

    /**
     * The smaller of PHP's upload_max_filesize and post_max_size in bytes (PHP_INT_MAX when both are unlimited).
     */
    protected function phpMaxBytes(): int
    {
        return app(ImportUploadLimit::class)->phpMaxBytes();
    }

    /**
     * The limit that applies: the configured one, or PHP's when that is lower.
     */
    private function maxBytes(): int
    {
        return ImportUploadLimit::clamp($this->phpMaxBytes());
    }

    private function limitLabel(): string
    {
        $bytes = $this->maxBytes();

        return $bytes >= 1024 * 1024 ? intdiv($bytes, 1024 * 1024).' MB' : max(1, intdiv($bytes, 1024)).' KB';
    }

    private function phpLimitRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof UploadedFile && $value->getSize() > $this->maxBytes()) {
                $fail('The file is larger than the upload limit of '.$this->limitLabel().'. Import larger databases with the command-line importer.');
            }
        };
    }
}
