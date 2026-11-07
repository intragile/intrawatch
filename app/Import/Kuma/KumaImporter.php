<?php

declare(strict_types=1);

namespace App\Import\Kuma;

use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Steps\ImportNotifications;
use App\Import\Kuma\Steps\ImportStep;
use App\Import\Kuma\Steps\ImportTags;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Container\Container;

/**
 * Imports an Uptime Kuma instance into a workspace by running the steps in dependency order. Re-running is
 * safe: already imported entities are reported as existing and never touched.
 */
final class KumaImporter
{
    /**
     * Steps in execution order. Append a step class to add a stage; later stages resolve references of
     * earlier ones through the mapping store.
     *
     * @var list<class-string<ImportStep>>
     */
    public const STEPS = [
        ImportTags::class,
        ImportNotifications::class,
    ];

    /**
     * @param  list<class-string<ImportStep>>  $steps
     */
    public function __construct(private readonly array $steps = self::STEPS) {}

    public function run(KumaSource $source, Workspace $workspace, ImportOptions $options, ?User $actor): ImportReport
    {
        $report = new ImportReport($source, $options->dryRun);
        $mappings = new MappingStore($workspace, $source, $options->dryRun);
        $ctx = new ImportContext($source, $workspace, $actor, $options, $mappings, $report);

        if ($mappings->hasOtherInstanceKind()) {
            $report->note(Note::of(
                'other_instance_kind',
                'This workspace already holds entities imported from the other kind of source (JSON backup versus database). '
                .'The two cannot be matched, so entities imported now may duplicate the earlier ones.',
            ));
        }

        foreach ($this->steps as $class) {
            $step = Container::getInstance()->make($class);
            if (! $step instanceof ImportStep) {
                throw new \LogicException("{$class} is not an import step.");
            }
            $step->run($ctx);
        }

        $report->finish();

        return $report;
    }
}
