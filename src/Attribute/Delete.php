<?php

namespace Odnavi\Routing\Attribute;

use Attribute;

/** Операция удаления сущности: DELETE /{id}. */
#[Attribute(Attribute::TARGET_CLASS)]
class Delete extends Operation
{
    protected const METHOD       = 'DELETE';
    protected const PATH         = '/{id}';
    protected const REQUIREMENTS = ['id' => '\d+'];
    protected const HANDLER      = 'opDelete';
    protected const HOOK_BEFORE  = 'beforeDelete';
    protected const HOOK_AFTER   = 'afterDelete';
}
