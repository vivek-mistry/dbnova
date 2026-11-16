<?php

namespace App\Database\DTOs\Schema;

/**
 * A column as the user describes it in a form. The DDL builder validates it
 * and turns it into SQL.
 */
final readonly class ColumnDefinition
{
    /**
     * @param  string|null  $length  Length or precision, e.g. "255" or "10,2".
     * @param  list<string>  $values  Allowed values for ENUM and SET columns.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $length = null,
        public array $values = [],
        public bool $unsigned = false,
        public bool $nullable = true,
        public ColumnDefault $default = new ColumnDefault,
        public bool $autoIncrement = false,
        public bool $onUpdateCurrentTimestamp = false,
        public ?string $collation = null,
        public string $comment = '',
    ) {}
}
