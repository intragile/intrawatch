<?php

namespace App\OpenApi;

use App\Http\Pagination\CursorPage;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents the `cursor` and `limit` query parameters on every operation whose 200 response is a cursor page
 * (the `data` and `meta.next_cursor` envelope built by `CursorPage::from()`).
 */
final class CursorPageParameters
{
    public function __invoke(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! $this->returnsCursorPage($operation)) {
            return;
        }

        $existing = array_map(static fn (Parameter|Reference $parameter): string => $parameter instanceof Parameter ? $parameter->in.':'.$parameter->name : '', $operation->parameters);

        if (! in_array('query:cursor', $existing, true)) {
            $cursor = new Parameter('cursor', 'query');
            $cursor->description = 'Opaque cursor taken from `meta.next_cursor` of the previous page; omit it for the first page.';
            $cursor->setSchema($this->schema(new StringType));
            $operation->addParameters([$cursor]);
        }

        if (! in_array('query:limit', $existing, true)) {
            $limit = new Parameter('limit', 'query');
            $limit->description = 'Page size.';
            $type = new IntegerType;
            $type->setMin(1);
            $type->setMax(CursorPage::MAX_LIMIT);
            $type->default(CursorPage::DEFAULT_LIMIT);
            $limit->setSchema($this->schema($type));
            $operation->addParameters([$limit]);
        }
    }

    private function schema(Type $type): Schema
    {
        $schema = new Schema;
        $schema->type = $type;

        return $schema;
    }

    private function returnsCursorPage(Operation $operation): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || $response->code !== 200) {
                continue;
            }

            $schema = $response->getContent('application/json');
            $type = $schema instanceof Schema ? $schema->type : null;
            if (! $type instanceof ObjectType || ! isset($type->properties['data'], $type->properties['meta'])) {
                continue;
            }

            $meta = $type->properties['meta'];
            if ($meta instanceof ObjectType && isset($meta->properties['next_cursor'], $meta->properties['limit'])) {
                return true;
            }
        }

        return false;
    }
}
