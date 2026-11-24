<?php

use Illuminate\Support\Facades\DB;
use Sevaske\Zatca\Models\Document;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Sevaske\ZatcaApi\Invoice\InvoiceXml;

/*
 * Several processes issue documents of one unit at the same time, as queue workers on several servers do.
 * Needs MySQL (SQLite has no row locks) and pcntl:
 *
 *     ZATCA_MYSQL=1 vendor/bin/pest --group=mysql
 *
 * Connection: DB_HOST (127.0.0.1), DB_PORT (3306), DB_USERNAME (root), DB_PASSWORD (empty); database zatca_test.
 */
beforeEach(function () {
    // here, not in skip(): Pest runs beforeEach before it evaluates skip()
    if (! getenv('ZATCA_MYSQL') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('set ZATCA_MYSQL=1 to run against MySQL');
    }

    $connection = [
        'driver' => 'mysql',
        'prefix' => '',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];

    config()->set('database.connections.mysql', $connection + ['database' => '']);
    DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS zatca_test');
    DB::purge('mysql');

    config()->set('database.connections.mysql', $connection + ['database' => 'zatca_test']);
    config()->set('zatca.database.connection', 'mysql');

    $migration = include __DIR__.'/../../database/migrations/create_zatca_tables.php.stub';
    $migration->down();
    $migration->up();
});

it('gives concurrent workers consecutive counters and an unbroken chain', function () {
    $workers = 4;
    $documentsPerWorker = 10;
    $unit = $this->unit();
    $errors = tempnam(sys_get_temp_dir(), 'zatca');

    // children must open their own connections: never share the parent's socket
    DB::disconnect('mysql');
    $children = [];

    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                for ($n = 0; $n < $documentsPerWorker; $n++) {
                    EgsUnit::query()->findOrFail($unit->id)->issue(InvoiceData::make($n % 2 ? 'standard' : 'simplified'));
                }
            } catch (Throwable $e) {
                file_put_contents($errors, $e->getMessage().PHP_EOL, FILE_APPEND);
            }

            // leave without running the test runner's shutdown handlers
            posix_kill(getmypid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $documents = Document::query()->orderBy('icv')->get();
    $unit->refresh();

    expect(file_get_contents($errors))->toBe('')
        ->and($documents)->toHaveCount($workers * $documentsPerWorker)
        ->and($documents->pluck('icv')->all())->toBe(range(1, $workers * $documentsPerWorker))
        ->and($unit->last_icv)->toBe($workers * $documentsPerWorker)
        ->and($unit->last_hash)->toBe($documents->last()->hash);

    $documents->sliding(2)->each(fn ($pair) => expect($pair->last()->pih)->toBe($pair->first()->hash));

    // the XML comes back byte for byte: its hash is still the one computed at signing
    $documents->each(fn (Document $document) => expect(InvoiceXml::hash($document->xml))->toBe($document->hash));
})->group('mysql');
