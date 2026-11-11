<?php

namespace Sevaske\Zatca;

use GuzzleHttp\Client as GuzzleClient;
use Sevaske\Zatca\Commands\ZatcaComplianceCertificate;
use Sevaske\Zatca\Commands\ZatcaGenerateCsr;
use Sevaske\Zatca\Commands\ZatcaProductionCertificate;
use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\Zatca\Files\ZatcaComplianceCredentials;
use Sevaske\Zatca\Files\ZatcaCsr;
use Sevaske\Zatca\Files\ZatcaPrivateKey;
use Sevaske\Zatca\Files\ZatcaProductionCredentials;
use Sevaske\Zatca\Invoice\InvoiceService;
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
            ->hasCommands([
                ZatcaGenerateCsr::class,
                ZatcaComplianceCertificate::class,
                ZatcaProductionCertificate::class,
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
        $this->app->bind(ZatcaFilesContract::class, fn () => new ZatcaFiles(
            new ZatcaCsr,
            new ZatcaPrivateKey,
            new ZatcaComplianceCredentials,
            new ZatcaProductionCredentials,
        ));

        // scoped, not singleton: config and credentials are re-read per request / Octane tick
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

        // the services look the client up on every call, so swapping it (tests, custom transport) always works
        $this->app->bind(InvoiceService::class, fn ($app) => new InvoiceService(
            fn () => $app->make(Client::class),
            $app->make(ZatcaFilesContract::class),
        ));

        $this->app->bind(Zatca::class, fn ($app) => new Zatca(
            fn () => $app->make(Client::class),
            $app->make(ZatcaFilesContract::class),
            $app->make(InvoiceService::class),
        ));
    }
}
