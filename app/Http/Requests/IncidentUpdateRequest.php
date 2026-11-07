<?php

namespace App\Http\Requests;

use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of incident timeline writes. A new entry carries a status and a message; an edit changes the
 * message only. The message is stored verbatim.
 */
class IncidentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether the request creates a timeline entry; the update request overrides this.
     */
    protected function creating(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['message' => ['required', 'string', 'max:10000']];

        if ($this->creating()) {
            $rules['status'] = ['required', Rule::enum(IncidentStatus::class)];
        }

        return $rules;
    }
}
