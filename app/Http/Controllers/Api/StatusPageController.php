<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\StatusPageRequest;
use App\Http\Requests\UpdateStatusPageRequest;
use App\Http\Resources\StatusPageResource;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class StatusPageController extends Controller
{
    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Lists the status pages of the workspace without their layout.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $query = $workspace->statusPages()->orderBy('status_pages.id');

        return CursorPage::from($query, $request, fn (Model $m): array => StatusPageResource::toArray($this->page($m)));
    }

    public function show(Workspace $workspace, StatusPage $statusPage): JsonResponse
    {
        return $this->respond($statusPage->id);
    }

    public function store(StatusPageRequest $request, Workspace $workspace): JsonResponse
    {
        $page = DB::transaction(function () use ($request, $workspace): StatusPage {
            $page = $workspace->statusPages()->create($request->pageAttributes());
            Audit::record('status_page.created', $page, [
                'slug' => [null, $page->slug],
                'title' => [null, $page->title],
                'published' => [null, $page->published],
            ]);

            return $page;
        });
        $this->stamps->forgetPage($page);

        return $this->respond($page->id, 201);
    }

    public function update(UpdateStatusPageRequest $request, Workspace $workspace, StatusPage $statusPage): JsonResponse
    {
        $oldSlug = $statusPage->slug;
        $oldDomain = $statusPage->custom_domain;

        DB::transaction(function () use ($request, $statusPage): void {
            $old = $statusPage->only(array_keys($request->pageAttributes()));
            $statusPage->fill($request->pageAttributes());
            $diff = [];
            foreach ($statusPage->getDirty() as $field => $_) {
                $diff[$field] = [$this->audited($field, $old[$field] ?? null), $this->audited($field, $statusPage->getAttribute($field))];
            }
            $statusPage->save();
            if ($diff !== []) {
                Audit::record('status_page.updated', $statusPage, $diff);
            }
        });

        if ($oldSlug !== $statusPage->slug || $oldDomain !== $statusPage->custom_domain) {
            $this->stamps->forgetKeys($statusPage->id, $oldSlug, $oldDomain);
        }
        $this->stamps->forgetPage($statusPage);

        return $this->respond($statusPage->id);
    }

    public function destroy(Workspace $workspace, StatusPage $statusPage): Response
    {
        DB::transaction(function () use ($statusPage): void {
            $statusPage->delete();
            Audit::record('status_page.deleted', $statusPage, ['slug' => [$statusPage->slug, null], 'title' => [$statusPage->title, null]]);
        });
        $this->stamps->forgetPage($statusPage);

        return response()->noContent();
    }

    private function respond(int $id, int $status = 200): JsonResponse
    {
        $fresh = StatusPage::query()->with('sections.items')->findOrFail($id);

        return response()->json(['data' => StatusPageResource::toArray($fresh)], $status);
    }

    /**
     * Long free text stays out of the trail; its length is enough to see that it changed.
     */
    private function audited(string $field, mixed $value): mixed
    {
        if (in_array($field, ['custom_css', 'footer', 'description'], true) && is_string($value)) {
            return strlen($value).' bytes';
        }

        return $value;
    }

    private function page(Model $model): StatusPage
    {
        return $model instanceof StatusPage ? $model : throw new \LogicException('Expected a status page.');
    }
}
