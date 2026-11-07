<?php

namespace App\Models;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A manually published incident with a timeline of updates.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $title
 * @property IncidentImpact $impact
 * @property IncidentStatus $status Status of the latest update.
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 */
#[Fillable(['workspace_id', 'title', 'impact', 'status', 'started_at', 'resolved_at'])]
class Incident extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Incident $incident): void {
            DB::table('status_page_links')
                ->where('linkable_type', 'incident')
                ->where('linkable_id', $incident->id)
                ->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impact' => IncidentImpact::class,
            'status' => IncidentStatus::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'incident_monitor');
    }

    /**
     * @return BelongsToMany<StatusPage, $this>
     */
    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class, 'status_page_links', 'linkable_id', 'status_page_id')
            ->wherePivot('linkable_type', 'incident');
    }
}
