<?php

namespace Sevaske\Zatca\Files;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Contracts\ZatcaFileContract;

/**
 * A single file on a Laravel filesystem disk.
 *
 * The disk and path are read from the config on every call, so the object stays correct
 * when the configuration changes at runtime (tests, Octane, multi-tenant apps).
 */
abstract class ZatcaFile implements ZatcaFileContract
{
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('zatca.storage.credentials_disk');
    }

    public function get(): ?string
    {
        try {
            return $this->disk()->get($this->path());
        } catch (FileNotFoundException) {
            return null;
        }
    }

    public function exists(): bool
    {
        return $this->disk()->exists($this->path());
    }

    public function put(string $contents): bool
    {
        return $this->disk()->put($this->path(), $contents);
    }

    abstract public function path(): string;
}
