<?php

namespace Sevaske\Zatca\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

abstract class ZatcaFileGenerating extends Command
{
    protected function askFilePathToPut(Filesystem $disk, string $question, ?string $default): string
    {
        $path = trim((string) $this->ask($question, $default));

        if ($disk->exists($path)) {
            $confirmationMessage = 'The file '.$path.' already exists. Do you want to replace it?';

            if (! $this->confirm($confirmationMessage)) {
                return $this->askFilePathToPut($disk, $question, $default);
            }
        }

        return $path;
    }

    protected function chooseDisk(): Filesystem
    {
        return Storage::disk($this->choice(
            __('zatca::zatca.choose_disk'),
            array_keys(config('filesystems.disks')),
            config('zatca.storage.credentials_disk'))
        );
    }
}
