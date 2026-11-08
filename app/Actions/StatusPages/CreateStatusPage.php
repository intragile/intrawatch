<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Actions\Concerns\RunsInWorkspace;
use App\Http\Requests\StatusPageRequest;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use App\Support\RequestInput;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a status page. Validation is the one of `POST /status-pages`: the same form request evaluates the
 * input, so the API, the import and any other caller accept and refuse the same values with the same error
 * keys.
 */
class CreateStatusPage
{
    use RunsInWorkspace;

    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Validates a create payload (the body of `POST /status-pages`) and returns the page columns `handle`
     * takes. Setting `custom_domain` needs `manageCustomDomains` on the workspace; a null actor is the system
     * and may always set it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws AuthorizationException When the actor may not set the custom domain.
     */
    public function validate(Workspace $workspace, array $input, ?User $actor = null): array
    {
        return $this->inWorkspace($workspace, function () use ($input, $actor): array {
            $request = StatusPageRequest::create('/', 'POST', RequestInput::normalize($input));
            $request->setContainer(Container::getInstance())->setRedirector(app(Redirector::class));
            if ($actor === null) {
                $request->asSystem();
            } else {
                $request->setUserResolver(static fn (): User => $actor);
            }
            $request->validateResolved();

            return $request->pageAttributes();
        });
    }

    /**
     * Persists a validated page with its audit row. `$within` runs inside the transaction after the page is
     * written, so work that must commit together with the page (its layout, an import mapping) can join it.
     * A null actor records the caller of the current request, or the system.
     *
     * @param  array<string, mixed>  $validated  The result of `validate`.
     * @param  (Closure(StatusPage): void)|null  $within
     */
    public function handle(Workspace $workspace, array $validated, ?User $actor, ?Closure $within = null): StatusPage
    {
        $page = DB::transaction(function () use ($workspace, $validated, $actor, $within): StatusPage {
            $page = $workspace->statusPages()->create($validated);
            Audit::record('status_page.created', $page, [
                'slug' => [null, $page->slug],
                'title' => [null, $page->title],
                'published' => [null, $page->published],
            ], $actor, $workspace);
            if ($within !== null) {
                $within($page);
            }

            return $page;
        });
        $this->stamps->forgetPage($page);

        return $page;
    }
}
