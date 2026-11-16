<?php

namespace App\Services\System;

final readonly class SystemCheck
{
    public function __construct(
        public string $name,
        public SystemCheckStatus $status,
        public string $detail,
        public ?string $hint = null,
    ) {}

    public static function ok(string $name, string $detail): self
    {
        return new self($name, SystemCheckStatus::Ok, $detail);
    }

    public static function warning(string $name, string $detail, ?string $hint = null): self
    {
        return new self($name, SystemCheckStatus::Warning, $detail, $hint);
    }

    public static function failed(string $name, string $detail, ?string $hint = null): self
    {
        return new self($name, SystemCheckStatus::Failed, $detail, $hint);
    }

    /**
     * @return array{name: string, status: string, detail: string, hint: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
