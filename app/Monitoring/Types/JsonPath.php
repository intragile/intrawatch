<?php

namespace App\Monitoring\Types;

use Flow\JSONPath\JSONPath as Evaluator;
use Flow\JSONPath\JSONPathException;
use Throwable;

/**
 * JSONPath validation and evaluation backed by softcreatr/jsonpath, so a path accepted at validation time is
 * parsed by the same implementation when a check runs.
 */
final class JsonPath
{
    public const MAX_LENGTH = 1024;

    /**
     * A small document with objects, scalars and nested lists. Filter expressions are only parsed when they are
     * applied, so validation applies every segment to it once.
     */
    private const PROBE = [['a' => 1, 'b' => 'x', 'c' => [1, 2]], 1, 'x'];

    public static function isValid(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_LENGTH) {
            return false;
        }

        try {
            foreach ((new Evaluator)->parseTokens($path) as $token) {
                $filter = $token->buildFilter(0);
                $filter->setRootData(self::PROBE);
                $filter->filter(self::PROBE);
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Every value the path selects in the decoded document, in document order.
     *
     * @return list<mixed>
     *
     * @throws JSONPathException When the path cannot be evaluated.
     */
    public static function query(mixed $document, string $path): array
    {
        $result = (new Evaluator($document))->find($path)->getData();

        return is_array($result) ? array_values($result) : [];
    }
}
