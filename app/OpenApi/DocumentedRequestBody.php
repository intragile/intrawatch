<?php

namespace App\OpenApi;

/**
 * A form request whose runtime rules depend on the submitted type or the bound model, so they cannot be
 * evaluated without a real request. It describes its body for the API reference instead.
 */
interface DocumentedRequestBody
{
    /**
     * The request body as the API reference documents it, written as validation rules. These rules are
     * never used to validate a request.
     *
     * @return array<string, mixed>
     */
    public function documentedRules(): array;
}
