<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Database\Factories\IncidentUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of an incident timeline.
 *
 * @property int $id
 * @property int $incident_id
 * @property IncidentStatus $status
 * @property string $message
 * @property Carbon $created_at
 */
#[Fillable(['incident_id', 'status', 'message'])]
class IncidentUpdate extends Model
{
    /** @use HasFactory<IncidentUpdateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => IncidentStatus::class];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
