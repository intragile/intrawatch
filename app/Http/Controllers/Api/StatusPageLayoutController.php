<?php

namespace App\Http\Controllers\Api;

use App\Actions\StatusPages\ReplaceStatusPageLayout;
use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPageLayoutRequest;
use App\Http\Resources\StatusPageResource;
use App\Models\StatusPage;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

class StatusPageLayoutController extends Controller
{
    public function __construct(private readonly ReplaceStatusPageLayout $replace) {}

    /**
     * Replaces all sections and items of the page. The order of the arrays is the display order.
     */
    public function update(StatusPageLayoutRequest $request, Workspace $workspace, StatusPage $statusPage): JsonResponse
    {
        $this->replace->handle($workspace, $statusPage, $request->layout(), null);

        $fresh = StatusPage::query()->with('sections.items')->findOrFail($statusPage->id);

        return response()->json(['data' => StatusPageResource::toArray($fresh)]);
    }
}
