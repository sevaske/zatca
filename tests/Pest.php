<?php

use GuzzleHttp\Psr7\Response;
use Sevaske\Zatca\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A JSON answer of the ZATCA API.
 *
 * @param  array<string, mixed>  $body
 */
function zatcaJson(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

/**
 * ZATCA's answer to an invalid document: NOT_REPORTED or NOT_CLEARED, with one error.
 */
function rejection(string $statusKey): Response
{
    return zatcaJson(400, [$statusKey => str_starts_with($statusKey, 'clear') ? 'NOT_CLEARED' : 'NOT_REPORTED', 'validationResults' => [
        'status' => 'ERROR',
        'warningMessages' => [],
        'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'bad address', 'status' => 'ERROR']],
    ]]);
}
