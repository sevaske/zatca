<?php

namespace Sevaske\Zatca\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Sevaske\ZatcaApi\Api;
use Sevaske\ZatcaApi\Enums\ZatcaEnvironmentEnum;

abstract class ZatcaOnboardingCommand extends Command
{
    protected function chooseDisk(): Filesystem
    {
        return Storage::disk($this->choice(
            __('zatca::zatca.choose_disk'),
            array_keys(config('filesystems.disks')),
            config('zatca.storage.credentials_disk'))
        );
    }

    protected function askFilePathToPut(Filesystem $disk, string $question, ?string $default): ?string
    {
        $path = trim((string) $this->ask($question, $default));

        // if file does not exist, return path
        if (! $disk->exists($path)) {
            return $path;
        }

        // file exists, ask if should replace
        if ($this->confirm(__('zatca::zatca.file_exists_confirm', ['path' => $disk->path($path)]))) {
            return $path;
        }

        // ask if user wants to cancel saving
        if ($this->confirm(__('zatca::zatca.confirm_cancel_file_save'))) {
            $this->warn(__('zatca::zatca.process_stopped'));

            return null;
        }

        // ask for file path again
        return $this->askFilePathToPut($disk, $question, $default);
    }

    protected function apiClient(ZatcaEnvironmentEnum|string $env, ?string $certificate = null, ?string $secret = null): Api
    {
        $env = $env instanceof ZatcaEnvironmentEnum ? $env : ZatcaEnvironmentEnum::from($env);

        // singleton in the provider
        if ($env->value === config('zatca.env')) {
            $api = app(Api::class);
            $api->setCredentials($certificate, $secret);

            return $api;
        }

        $httpClient = new Client([
            'base_uri' => $env->url(),
            'timeout' => 60,
            'verify' => true,
        ]);

        return new Api($env->value, $httpClient, $certificate, $secret);
    }
}
