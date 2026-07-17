<?php

namespace Odnavi\Routing\Attribute;

use Attribute;

/** Операция получения списка: GET на группу ресурса. */
#[Attribute(Attribute::TARGET_CLASS)]
class GetCollection extends Operation
{
    protected const METHOD  = 'GET';
    protected const PATH    = '';
    protected const HANDLER = 'opList';
}
