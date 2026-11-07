<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPageLayoutRequest;
use App\Http\Resources\StatusPageResource;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StatusPageLayoutController extends Controller
{
    private const CHUNK = 500;

    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Replaces all sections and items of the page. The order of the arrays is the display order.
     */
    public function update(StatusPageLayoutRequest $request, Workspace $workspace, StatusPage $statusPage): JsonResponse
    {
        $layout = $request->layout();

        DB::transaction(function () use ($statusPage, $layout): void {
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
            ]);
        });
        $this->stamps->bumpPage($statusPage->id);

        $fresh = StatusPage::query()->with('sections.items')->findOrFail($statusPage->id);

        return response()->json(['data' => StatusPageResource::toArray($fresh)]);
    }
}
