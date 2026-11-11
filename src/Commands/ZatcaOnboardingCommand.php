<?php

namespace Sevaske\Zatca\Commands;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;
use Sevaske\ZatcaApi\Environment;

abstract class ZatcaOnboardingCommand extends Command
{
    /**
     * The value of an option, or the answer to a question when the option is not given.
     *
     * Without an interactive terminal the default is used.
     */
    protected function optionOrAsk(string $option, string $question, ?string $default = null): string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : (string) $this->ask($question, $default);
    }

    protected function chooseEnvironment(): string
    {
        $env = $this->option('env');

        if (is_string($env) && $env !== '') {
            if (! Environment::isValid($env)) {
                $this->fail("Unknown environment \"{$env}\". Expected one of: ".implode(', ', Environment::all()).'.');
            }

            return $env;
        }

        return (string) $this->choice(__('zatca::zatca.select_environment'), Environment::all(), config('zatca.env'));
    }

    protected function chooseDisk(): Filesystem
    {
        $disk = $this->option('disk');

        if (! is_string($disk) || $disk === '') {
            $disk = (string) $this->choice(
                __('zatca::zatca.choose_disk'),
                array_keys(config('filesystems.disks')),
                config('zatca.storage.credentials_disk'),
            );
        }

        return Storage::disk($disk);
    }

    /**
     * Resolve where to write a file: the given option, or a prompt. Returns null if the user cancels.
     *
     * An existing file is only overwritten with --force or after confirmation.
     */
    protected function resolveOutputPath(Filesystem $disk, string $option, string $question, string $default): ?string
    {
        $path = trim($this->optionOrAsk($option, $question, $default));

        if ($path === '' || ! $disk->exists($path) || $this->option('force')) {
            return $path === '' ? null : $path;
        }

        if ($this->confirm(__('zatca::zatca.file_exists_confirm', ['path' => $path]))) {
            return $path;
        }

        if ($this->input->isInteractive() && ! $this->confirm(__('zatca::zatca.confirm_cancel_file_save'))) {
            return $this->resolveOutputPath($disk, $option, $question, $default);
        }

        $this->warn(__('zatca::zatca.process_stopped'));

        return null;
    }

    /**
     * Save a secret to the disk. Secrets are never printed, unless saving failed or was declined.
     */
    protected function saveOrPrint(Filesystem $disk, string $option, string $question, string $default, string $contents, string $label): bool
    {
        $shouldSave = $this->option($option) || $this->confirm(__('zatca::zatca.confirm_saving_on_disk'), true);
        $path = $shouldSave ? $this->resolveOutputPath($disk, $option, $question, $default) : null;

        if ($path !== null) {
            if ($disk->put($path, $contents)) {
                $this->info(__('zatca::zatca.saved_to', ['label' => $label, 'path' => $path]));

                return true;
            }

            $this->error(__('zatca::zatca.file_save_failed', ['path' => $path]));
        }

        // nothing was saved: this is the only copy, so show it
        $this->line($label.':');
        $this->line($contents);

        return false;
    }

    protected function apiClient(string $env): Client
    {
        // the container client is bound to the configured environment
        if ($env === config('zatca.env')) {
            return app(Client::class);
        }

        return new Client($env, ClientOptions::create()->withHttpClient(new GuzzleClient([
            'timeout' => (float) config('zatca.http.timeout', 60),
            'connect_timeout' => (float) config('zatca.http.connect_timeout', 10),
            'allow_redirects' => false,
            'http_errors' => false,
        ])));
    }
}
