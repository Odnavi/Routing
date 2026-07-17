<?php

namespace Odnavi\Routing\Attribute;

use Attribute;

/** Операция создания сущности: POST на группу ресурса. */
#[Attribute(Attribute::TARGET_CLASS)]
class Post extends Operation
{
    protected const METHOD      = 'POST';
    protected const PATH        = '';
    protected const HANDLER     = 'opCreate';
    protected const HOOK_BEFORE = 'beforeCreate';
    protected const HOOK_AFTER  = 'afterCreate';
}
