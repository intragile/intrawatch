<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Actions\Concerns\RunsInWorkspace;
use App\Http\Requests\StatusPageLayoutRequest;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use App\Support\RequestInput;
use Illuminate\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces all sections and items of a status page. Validation is the one of the layout endpoint. The
 * transaction joins an enclosing one, so a page and its first layout can be committed together.
 */
class ReplaceStatusPageLayout
{
    use RunsInWorkspace;

    private const CHUNK = 500;

    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Validates a layout payload (`sections`) and returns the layout `handle` takes. With `$checkMonitors`
     * false the monitors need not exist yet; only the structure and the limits are checked.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{name: string, description: string|null, items: list<array<string, mixed>>}>
     *
     * @throws ValidationException
     */
    public function validate(Workspace $workspace, array $input, bool $checkMonitors = true): array
    {
        return $this->inWorkspace($workspace, function () use ($input, $checkMonitors): array {
            $request = StatusPageLayoutRequest::create('/', 'PUT', RequestInput::normalize($input));
            $request->setContainer(Container::getInstance())->setRedirector(app(Redirector::class));
            if (! $checkMonitors) {
                $request->withoutOwnershipChecks();
            }
            $request->validateResolved();

            return $request->layout();
        });
    }

    /**
     * Replaces the page's sections and items and records the audit row. A null actor records the caller of
     * the current request, or the system.
     *
     * @param  list<array{name: string, description: string|null, items: list<array<string, mixed>>}>  $layout  The result of `validate`.
     */
    public function handle(Workspace $workspace, StatusPage $statusPage, array $layout, ?User $actor): void
    {
        DB::transaction(function () use ($workspace, $statusPage, $layout, $actor): void {
            StatusPage::query()->lockForUpdate()->findOrFail($statusPage->id);
            $oldSections = DB::table('status_page_sections')->where('status_page_id', $statusPage->id)->count();
            $oldItems = DB::table('status_page_items')
                ->join('status_page_sections', 'status_page_sections.id', '=', 'status_page_items.status_page_section_id')
                ->where('status_page_sections.status_page_id', $statusPage->id)->count();

            DB::table('status_page_sections')->where('status_page_id', $statusPage->id)->delete();

            $items = 0;
            foreach ($layout as $position => $section) {
                $sectionId = DB::table('status_page_sections')->insertGetId([
                    'status_page_id' => $statusPage->id,
                    'name' => $section['name'],
                    'description' => $section['description'],
                    'position' => $position,
                ]);
                $rows = [];
                foreach ($section['items'] as $itemPosition => $item) {
                    $rows[] = $item + ['status_page_section_id' => $sectionId, 'position' => $itemPosition];
                }
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('status_page_items')->insert($chunk);
                }
                $items += count($rows);
            }

            $statusPage->touch();
            Audit::record('status_page.layout_replaced', $statusPage, [
                'sections' => [$oldSections, count($layout)],
                'items' => [$oldItems, $items],
            ], $actor, $workspace);
        });
        // Removed components must not come back from the last good copy during an outage. Inside an enclosing
        // transaction this waits for its commit.
        DB::afterCommit(fn () => $this->stamps->forgetPage($statusPage));
    }
}
