<?php

namespace Odnavi\Routing\Attribute;

use Attribute;

/** Операция частичного обновления сущности: PATCH /{id}. */
#[Attribute(Attribute::TARGET_CLASS)]
class Patch extends Operation
{
    protected const METHOD       = 'PATCH';
    protected const PATH         = '/{id}';
    protected const REQUIREMENTS = ['id' => '\d+'];
    protected const HANDLER      = 'opUpdate';
    protected const HOOK_BEFORE  = 'beforeUpdate';
    protected const HOOK_AFTER   = 'afterUpdate';
}
