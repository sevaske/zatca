<?php

namespace Sevaske\Zatca\Contracts;


interface ZatcaFileContract
{
    public function get(): ?string;

    public function exists(): bool;

    public function put(string $contents): bool;

    public function path(): string;

    public function diskName(): string;
}
