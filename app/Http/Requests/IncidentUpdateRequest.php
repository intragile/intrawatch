<?php

namespace App\Http\Requests;

use App\Enums\IncidentStatus;
use App\Models\IncidentUpdate;
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['message' => ['required', 'string', 'max:10000']];

        if (! $this->route('update') instanceof IncidentUpdate) {
            $rules['status'] = ['required', Rule::enum(IncidentStatus::class)];
        }

        return $rules;
    }
}
