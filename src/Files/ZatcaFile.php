<?php

namespace Sevaske\Zatca\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Contracts\ZatcaFileContract;

/**
 * Abstract base class for handling ZATCA-related file operations.
 *
 * Provides common methods for interacting with Laravel filesystem,
 * including reading, writing, and checking the existence of a file.
 * Concrete implementations must define the file path and disk name.
 */
abstract class ZatcaFile implements ZatcaFileContract
{
    /**
     * The Laravel filesystem disk instance.
     */
    protected Filesystem $disk;

    /**
     * Initialize the file by resolving the appropriate disk.
     * Concrete classes must define the `diskName()` method.
     */
    public function __construct()
    {
        $this->disk = Storage::disk($this->diskName());
    }

    /**
     * Get the absolute filesystem path to the file.
     */
    public function fullPath(): string
    {
        return $this->disk->path($this->path());
    }

    /**
     * Get the file contents, or null if the file does not exist.
     */
    public function get(): ?string
    {
        return $this->exists() ? $this->disk->get($this->path()) : null;
    }

    /**
     * Determine whether the file exists.
     */
    public function exists(): bool
    {
        return $this->disk->exists($this->path());
    }

    /**
     * Write contents to the file.
     */
    public function put(string $contents): bool
    {
        return $this->disk->put($this->path(), $contents);
    }

    /**
     * Get the relative path to the file on the disk.
     */
    abstract public function path(): string;

    /**
     * Get the name of the Laravel filesystem disk.
     */
    abstract public function diskName(): string;
}
