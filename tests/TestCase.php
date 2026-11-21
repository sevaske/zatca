<?php

namespace Sevaske\Zatca\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Tests\Fixtures\Credentials;
use Sevaske\Zatca\ZatcaServiceProvider;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;
use Throwable;

class TestCase extends Orchestra
{
    /** @var list<array{request: RequestInterface}> requests sent to the fake ZATCA API */
    protected array $requests = [];

    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // in-memory SQLite in every testbench version (testbench 8 defaults to mysql)
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('zatca.env', 'sandbox');
    }

    protected function defineDatabaseMigrations(): void
    {
        // every test gets a fresh in-memory SQLite database
        (include __DIR__.'/../database/migrations/create_zatca_tables.php.stub')->up();
    }

    /**
     * Answer ZATCA API calls with the given responses, in order. Any further call fails the test.
     */
    protected function fakeZatca(Response|Throwable ...$responses): MockHandler
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requests));

        $this->app->instance(Client::class, Client::sandbox(ClientOptions::create()->withHttpClient(
            new GuzzleClient(['handler' => $stack, 'allow_redirects' => false, 'http_errors' => false])
        )));

        return $mock;
    }

    /**
     * An onboarded unit with offline credentials.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function unit(string $key = 'default', array $attributes = []): EgsUnit
    {
        $credentials = Credentials::production($key);

        return EgsUnit::query()->create($attributes + [
            'key' => $key,
            'environment' => 'sandbox',
            'vat_number' => '399999999900003',
            'invoice_types' => '1100',
            'private_key' => $credentials->privateKey(),
            'production_csid' => $credentials->csid->toArray(),
        ]);
    }
}
