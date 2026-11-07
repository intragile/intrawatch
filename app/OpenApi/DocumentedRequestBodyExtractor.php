<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\PhpDoc;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\SchemaClassDocReflector;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds the request body of operations whose form request implements DocumentedRequestBody from its
 * documented rules. Scramble's own form request extractor skips those classes (see AppServiceProvider), as
 * it would evaluate their runtime rules without a type or a bound model.
 */
final class DocumentedRequestBodyExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(private readonly TypeTransformer $openApiTransformer) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $class = $this->requestClass($routeInfo);
        if ($class === null) {
            return $parameterExtractionResults;
        }

        $request = new $class;
        $request->setMethod(strtoupper($routeInfo->method));

        $reflection = new ReflectionClass($class);
        $doc = new SchemaClassDocReflector(PhpDoc::parse($reflection->getDocComment() ?: '/** */'));

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $this->makeParameters($request->documentedRules(), $this->openApiTransformer, [], 'body'),
            schemaName: $reflection->getShortName(),
            description: $doc->getDescription(),
            sourceClass: $class,
        );

        return $parameterExtractionResults;
    }

    /**
     * @return class-string<FormRequest&DocumentedRequestBody>|null
     */
    private function requestClass(RouteInfo $routeInfo): ?string
    {
        foreach ($routeInfo->reflectionAction()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }
            $name = $type->getName();
            if (is_a($name, FormRequest::class, true) && is_a($name, DocumentedRequestBody::class, true)) {
                return $name;
            }
        }

        return null;
    }
}
