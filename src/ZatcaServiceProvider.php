<?php

namespace Sevaske\Zatca;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Sevaske\Zatca\Commands\ZatcaComplianceCertificate;
use Sevaske\Zatca\Commands\ZatcaGenerateCsr;
use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\Zatca\Files\ZatcaComplianceCredentials;
use Sevaske\Zatca\Files\ZatcaCsr;
use Sevaske\Zatca\Files\ZatcaPrivateKey;
use Sevaske\Zatca\Files\ZatcaProductionCredentials;
use Sevaske\ZatcaApi\Api;
use Sevaske\ZatcaApi\Enums\ZatcaEnvironmentEnum;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZatcaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('zatca')
            ->hasCommands([
                ZatcaGenerateCsr::class,
                ZatcaComplianceCertificate::class,
            ])
            ->hasTranslations()
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('sevaske/zatca');
            });
    }

    public function registeringPackage(): void
    {
        // files
        $this->app->bind(ZatcaFilesContract::class, fn () => new ZatcaFiles(
            new ZatcaCsr,
            new ZatcaPrivateKey,
            new ZatcaComplianceCredentials,
            new ZatcaProductionCredentials,
        ));

        // api http client
        $this->app->singleton(Api::class, function () {
            $env = ZatcaEnvironmentEnum::from(config('zatca.env'));

            return new Api($env->value, new Client([
                'base_uri' => $env->url(),
                'timeout' => 60,
                'verify' => true,
            ]));
        });

        // main class
        $this->app->singleton(Zatca::class, function () {
            return new Zatca(app(Api::class), app(ZatcaFilesContract::class));
        });
    }

    public function packageBooted(): void
    {
        Http::macro('zatca', function () {
            return app(Api::class);
        });
    }
}
