<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\StatusPages\CreateStatusPage;
use App\Actions\StatusPages\ReplaceStatusPageLayout;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\Note;
use App\Import\Kuma\Mapping\StatusPageMapContext;
use App\Import\Kuma\Mapping\StatusPageMapper;
use App\Import\Kuma\Source\Cast;
use App\Import\Kuma\Steps\Concerns\ResolvesImportedTargets;
use App\Models\StatusPage;
use App\Status\Pages\PageResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports Kuma status pages, each with its layout and custom domain in one transaction together with its
 * mapping. Pages are published, since Kuma serves every page it holds.
 */
final class ImportStatusPages implements ImportStep
{
    use ResolvesImportedTargets;

    public const ENTITY = 'status_page';

    /** @var array<string, true> slugs given to pages created or planned in this run */
    private array $runSlugs = [];

    public function __construct(
        private readonly StatusPageMapper $mapper,
        private readonly CreateStatusPage $create,
        private readonly ReplaceStatusPageLayout $layout,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $this->runSlugs = [];
        $mapContext = $this->mapContext($ctx);

        foreach ($ctx->source->statusPages() as $row) {
            $sourceId = Cast::int($row['id'] ?? null);
            $label = Cast::string($row['title'] ?? null);

            try {
                $this->import($ctx, $mapContext, $sourceId, $row);
            } catch (Throwable $e) {
                // Only the class: exception messages may carry bindings or other data from the source.
                Log::warning('Kuma status page import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The status page could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(ImportContext $ctx, StatusPageMapContext $mapContext, int $sourceId, array $row): void
    {
        $label = Cast::string($row['title'] ?? null);

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = StatusPage::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            $note = $stillThere
                ? Note::of('already_imported', 'The status page was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The status page was imported earlier and has since been deleted; it was not recreated.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Existing, $mapping->target_id, [$note]);

            return;
        }

        $mapped = $this->mapper->map($row, $mapContext);
        $notes = $mapped->notes;
        $input = $mapped->page;
        $name = is_string($input['title'] ?? null) && $input['title'] !== '' ? $input['title'] : $label;

        $domain = $mapped->domains[0] ?? null;
        if (count($mapped->domains) > 1) {
            $notes[] = Note::of('extra_domains_ignored', 'IntraWatch serves a page on one custom domain; the other '.(count($mapped->domains) - 1).' domain(s) were ignored.');
        }
        if ($domain !== null && $ctx->actor !== null && ! PageResolver::allowsCustomDomain($domain)) {
            // The installation's allowlist binds an import started by a user exactly like the API.
            $notes[] = Note::of('custom_domain_not_allowed', 'The custom domain is not allowed on this installation; the page was imported without one.');
            $domain = null;
        }
        if ($domain !== null && ! $this->mayManageDomains($ctx)) {
            $notes[] = Note::of('custom_domain_not_permitted', 'Setting a custom domain needs the custom domain permission; the page was imported without one.');
            $domain = null;
        }

        try {
            try {
                $validated = $this->create->validate($ctx->workspace, $domain === null ? $input : $input + ['custom_domain' => $domain], $ctx->actor);
            } catch (ValidationException $e) {
                if ($domain === null || ! array_key_exists('custom_domain', $e->errors())) {
                    throw $e;
                }
                $notes[] = Note::of('custom_domain_rejected', 'The custom domain was refused ('.$e->validator->errors()->first('custom_domain').'); the page was imported without one.');
                $validated = $this->create->validate($ctx->workspace, $input, $ctx->actor);
            }
            $layout = $this->layout->validate($ctx->workspace, $mapped->layout, ! $ctx->options->dryRun);
        } catch (ValidationException $e) {
            $this->invalid($ctx, $sourceId, $name, $e);

            return;
        }

        $this->runSlugs[Cast::string($validated['slug'] ?? null)] = true;

        if ($ctx->options->dryRun) {
            $ctx->mappings->record(self::ENTITY, $sourceId, new StatusPage);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $notes);

            return;
        }

        try {
            $page = $this->create->handle($ctx->workspace, $validated, $ctx->actor, function (StatusPage $page) use ($ctx, $sourceId, $layout): void {
                $this->layout->handle($ctx->workspace, $page, $layout, $ctx->actor);
                $ctx->mappings->record(self::ENTITY, $sourceId, $page);
            });
        } catch (ValidationException $e) {
            $this->invalid($ctx, $sourceId, $name, $e);

            return;
        }

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $page->id, $notes);
    }

    /**
     * The system (no actor) may set custom domains; a user needs the permission, as for the API.
     */
    private function mayManageDomains(ImportContext $ctx): bool
    {
        return $ctx->actor === null || Gate::forUser($ctx->actor)->allows('manageCustomDomains', $ctx->workspace);
    }

    private function mapContext(ImportContext $ctx): StatusPageMapContext
    {
        $groups = [];
        foreach ($ctx->source->statusPageGroups() as $group) {
            $groups[Cast::int($group['status_page_id'] ?? null)][] = $group;
        }
        $items = [];
        foreach ($ctx->source->groupMonitors() as $item) {
            $items[Cast::int($item['group_id'] ?? null)][] = $item;
        }
        $domains = [];
        foreach ($ctx->source->statusPageDomains() as $cname) {
            $domain = trim(Cast::string($cname['domain'] ?? null));
            if ($domain !== '') {
                $domains[Cast::int($cname['status_page_id'] ?? null)][] = $domain;
            }
        }

        return new StatusPageMapContext(
            groups: $groups,
            items: $items,
            domains: $domains,
            slugTaken: fn (string $slug): bool => isset($this->runSlugs[$slug]) || DB::table('status_pages')->where('slug', $slug)->exists(),
            resolveMonitor: fn (int $id): ?int => $this->resolveMonitor($ctx, $id),
        );
    }

    private function invalid(ImportContext $ctx, int $sourceId, string $name, ValidationException $e): void
    {
        $errors = [];
        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $errors[(string) $field] = array_values($messages);
        }

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Invalid, null, [
            Note::of('status_page_invalid', 'The status page did not pass IntraWatch validation.'),
        ], $errors);
    }
}
