<?php

namespace App\Http\Pagination;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/**
 * Cursor pagination for every list endpoint.
 *
 * Response shape: {"data": [...], "meta": {"next_cursor": string|null, "limit": int}}.
 */
final class CursorPage
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    private const DATE_CASTS = [
        'date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime', 'timestamp',
    ];

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query  Ordered by primary key unless it already has an order.
     * @param  (Closure(Model): mixed)|null  $map  Item serialiser; defaults to the model's array form.
     */
    public static function from(Builder|Relation $query, Request $request, ?Closure $map = null): JsonResponse
    {
        $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $limit = min(self::MAX_LIMIT, $request->integer('limit', self::DEFAULT_LIMIT));

        $base = $query instanceof Relation ? $query->getQuery() : $query;
        $model = $base->getModel();
        if (! self::orderedBy($base, $model->getQualifiedKeyName()) && ! self::orderedBy($base, $model->getKeyName())) {
            $query->orderBy($model->getQualifiedKeyName());
        }

        $cursor = null;
        if ($request->has('cursor')) {
            $cursor = Cursor::fromEncoded($request->string('cursor')->toString());
            if ($cursor === null || ! self::isValidCursor($base, $cursor)) {
                throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
            }
        }

        $page = $query->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        $map ??= static fn (Model $model): mixed => $model->toArray();
        $data = [];
        foreach ($page->items() as $item) {
            if ($item instanceof Model) {
                $data[] = $map($item);
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'limit' => $limit],
        ]);
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function orderedBy(Builder $query, string $column): bool
    {
        foreach ($query->getQuery()->orders ?? [] as $order) {
            if (is_array($order) && ($order['column'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cursor must carry a scalar for every ordered column that fits the column's type: an integer for
     * the integer primary key and integer casts, a number for decimal casts and a parseable timestamp for
     * date casts. Malformed values would otherwise reach the database and fail there (or worse, compare
     * loosely).
     *
     * @param  Builder<*>  $query
     */
    private static function isValidCursor(Builder $query, Cursor $cursor): bool
    {
        $parameters = $cursor->toArray();
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $keyColumn = $model->getQualifiedKeyName();

        foreach ($query->getQuery()->orders ?? [] as $order) {
            $column = is_array($order) ? ($order['column'] ?? null) : null;
            if (! is_string($column)) {
                return false;
            }
            $name = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;
            $value = $parameters[$column] ?? $parameters[$name] ?? null;

            if (! is_scalar($value)) {
                return false;
            }

            $isKey = ($column === $keyColumn || $name === $keyName) && $model->getKeyType() === 'int';
            $valid = match (true) {
                $isKey, $model->hasCast($name, ['int', 'integer']) => filter_var($value, FILTER_VALIDATE_INT) !== false,
                $model->hasCast($name, ['float', 'double', 'real', 'decimal']) => is_numeric($value),
                in_array($name, $model->getDates(), true) || $model->hasCast($name, self::DATE_CASTS) => self::isTimestamp($value),
                default => true,
            };
            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    private static function isTimestamp(bool|float|int|string $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1
            && strtotime($value) !== false;
    }
}
