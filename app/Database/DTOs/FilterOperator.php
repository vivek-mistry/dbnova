<?php

namespace App\Database\DTOs;

enum FilterOperator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case Like = 'like';
    case NotLike = 'not_like';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';

    /**
     * Whether the operator compares against a value.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsNull, self::IsNotNull], true);
    }
}
