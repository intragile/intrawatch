<?php

declare(strict_types=1);

namespace App\Import\Kuma;

use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceKind;
use App\Models\ImportMapping;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Remembers which source entity became which IntraWatch entity, per workspace and Kuma instance, so that a
 * re-run finds what was already imported. In dry-run nothing is written; entities that would be created get
 * negative placeholder ids so later steps can still resolve references.
 */
final class MappingStore
{
    public const SOURCE = 'uptime_kuma';

    /** Instance key shared by every 1.23 JSON backup. */
    public static function jsonInstanceKey(): string
    {
        return hash('sha256', 'kuma-json');
    }

    /** @var array<string, array<int, ImportMapping>> persisted mappings, loaded per entity on first use */
    private array $persisted = [];

    /** @var array<string, array<int, int>> target ids resolved during this run (real ids, or placeholders in dry-run) */
    private array $resolved = [];

    /** @var array<string, array<int, int>> */
    private array $created = [];

    private int $placeholder = 0;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly KumaSource $source,
        private readonly bool $dryRun,
    ) {}

    public function find(string $entity, int $sourceId): ?ImportMapping
    {
        return $this->load($entity)[$sourceId] ?? null;
    }

    /**
     * Stores the mapping; call it inside the transaction that creates the entity. In dry-run it only registers
     * the target id in memory: the model's own key when it exists, a negative placeholder otherwise.
     *
     * @param  bool  $created  False when an existing entity was reused rather than created by the import.
     */
    public function record(string $entity, int $sourceId, Model $target, bool $created = true): void
    {
        if ($this->dryRun) {
            $id = $target->exists ? self::keyOf($target) : --$this->placeholder;
        } else {
            $id = self::keyOf($target);
            $mapping = ImportMapping::create([
                'workspace_id' => $this->workspace->id,
                'source' => self::SOURCE,
                'instance_key' => $this->source->instanceKey(),
                'entity' => $entity,
                'source_id' => $sourceId,
                'target_type' => $target->getMorphClass(),
                'target_id' => $id,
                'created_at' => Carbon::now(),
            ]);
            $this->load($entity);
            $this->persisted[$entity][$sourceId] = $mapping;
        }

        $this->resolved[$entity][$sourceId] = $id;
        if ($created) {
            $this->created[$entity][$sourceId] = $id;
        }
    }

    public function targetId(string $entity, int $sourceId): ?int
    {
        return $this->resolved[$entity][$sourceId] ?? $this->find($entity, $sourceId)?->target_id;
    }

    /**
     * Source id => target id of the entities created in this run (placeholders in dry-run).
     *
     * @return array<int, int>
     */
    public function createdInRun(string $entity): array
    {
        return $this->created[$entity] ?? [];
    }

    /**
     * Whether the workspace already holds mappings of the other kind of instance: a database import after a
     * JSON import, or the reverse. The two cannot be matched, so a second copy would be created.
     */
    public function hasOtherInstanceKind(): bool
    {
        $query = ImportMapping::query()
            ->forWorkspace($this->workspace)
            ->where('source', self::SOURCE);

        if ($this->source->kind() === SourceKind::Json) {
            $query->where('instance_key', '!=', self::jsonInstanceKey());
        } else {
            $query->where('instance_key', self::jsonInstanceKey());
        }

        return $query->exists();
    }

    private static function keyOf(Model $model): int
    {
        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : throw new \LogicException('The mapped entity has no numeric key.');
    }

    /**
     * @return array<int, ImportMapping>
     */
    private function load(string $entity): array
    {
        if (! isset($this->persisted[$entity])) {
            $rows = [];
            $query = ImportMapping::query()
                ->forWorkspace($this->workspace)
                ->where('source', self::SOURCE)
                ->where('instance_key', $this->source->instanceKey())
                ->where('entity', $entity);
            foreach ($query->get() as $mapping) {
                $rows[$mapping->source_id] = $mapping;
            }
            $this->persisted[$entity] = $rows;
        }

        return $this->persisted[$entity];
    }
}
