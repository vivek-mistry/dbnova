<?php

namespace App\Database\DTOs;

final readonly class CollationInfo
{
    public function __construct(
        public string $name,
        public string $characterSet,
        public bool $isDefault,
    ) {}

    /**
     * @return array{name: string, characterSet: string, isDefault: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'characterSet' => $this->characterSet,
            'isDefault' => $this->isDefault,
        ];
    }
}
