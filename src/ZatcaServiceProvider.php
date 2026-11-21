<?php

namespace Sevaske\Zatca;

use GuzzleHttp\Client as GuzzleClient;
use Sevaske\Zatca\Commands\ImportCommand;
use Sevaske\Zatca\Commands\OnboardCommand;
use Sevaske\Zatca\Commands\RenewCommand;
use Sevaske\Zatca\Commands\UnitsCommand;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZatcaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('zatca')
            ->hasConfigFile()
            ->hasMigration('create_zatca_tables')
            ->hasCommands([
                OnboardCommand::class,
                RenewCommand::class,
                ImportCommand::class,
                UnitsCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('sevaske/zatca');
            });
    }

    public function registeringPackage(): void
    {
        // scoped, not singleton: the config is read again per request / Octane tick
        $this->app->scoped(Client::class, function () {
            $options = ClientOptions::create()
                ->withHttpClient(new GuzzleClient([
                    'timeout' => (float) config('zatca.http.timeout', 60),
                    'connect_timeout' => (float) config('zatca.http.connect_timeout', 10),
                    'verify' => true,
                    // ZATCA answers 303 when clearance is deactivated: it must reach the library
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]))
                ->withLanguage((string) config('zatca.language', 'en'))
                ->withClearanceEnabled((bool) config('zatca.clearance_enabled', true));

            return new Client((string) config('zatca.env'), $options);
        });

        $this->app->bind(Zatca::class, fn ($app) => new Zatca($app));
    }
}
