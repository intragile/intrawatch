<?php

namespace App\OpenApi;

use App\Http\Pagination\CursorPage;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\DeferredPropertyFetchType;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\MixedType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Http\JsonResponse;

/**
 * Tells the API schema generator what `CursorPage::from()` returns: the page envelope with the items typed as
 * whatever the item serialiser closure returns.
 */
final class CursorPageReturnType implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === CursorPage::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        if ($event->name !== 'from') {
            return null;
        }

        $map = $event->getArg('map', 2, new NullType);
        $item = $map instanceof FunctionType ? $this->concrete($event, $map->getReturnType()) : new MixedType;

        $body = new KeyedArrayType([
            new ArrayItemType_('data', new ArrayType($item)),
            new ArrayItemType_('meta', new KeyedArrayType([
                new ArrayItemType_('next_cursor', Union::wrap([new StringType, new NullType])),
                new ArrayItemType_('limit', new IntegerType),
            ])),
        ]);

        return new Generic(JsonResponse::class, [$body, new LiteralIntegerType(200), new ArrayType]);
    }

    /**
     * The serialiser's return type with its model properties resolved. Inside the closure a model is still a
     * template bound to its class, so each template is replaced by that class before the properties are read;
     * whatever stays unknown is documented as any value rather than as a string.
     */
    private function concrete(StaticMethodCallEvent $event, Type $type): Type
    {
        $type = ReferenceTypeResolver::getInstance()->resolve($event->scope, $type);
        $type = (new TypeWalker)->replace($type, fn (Type $t): ?Type => $t instanceof TemplateType && $t->is instanceof ObjectType ? $t->is : null);
        $type = (new TypeWalker)->replace($type, fn (Type $t): ?Type => $t instanceof DeferredPropertyFetchType && $t->isResolvable() ? $t->resolve() : null);

        return (new TypeWalker)->replace($type, fn (Type $t): ?Type => $t instanceof UnknownType ? new MixedType : null);
    }
}
