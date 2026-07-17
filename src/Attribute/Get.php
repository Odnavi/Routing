<?php

namespace Odnavi\Routing\Attribute;

use Attribute;

/** Операция получения одной сущности: GET /{id}. */
#[Attribute(Attribute::TARGET_CLASS)]
class Get extends Operation
{
    protected const METHOD       = 'GET';
    protected const PATH         = '/{id}';
    protected const REQUIREMENTS = ['id' => '\d+'];
    protected const HANDLER      = 'opShow';
}
