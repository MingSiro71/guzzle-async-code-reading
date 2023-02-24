<?php

require __DIR__ . '/vendor/autoload.php';

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Echo debug trace with short code.
 *
 * @param string $message
 * @return void
 */
function trace(string $message): void
{
    $break = ($_SERVER['HTTP_USER_AGENT'] ?? '') === "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36"
        ? '<br>'
        : PHP_EOL;

    $backtrace = debug_backtrace();
    echo ($backtrace[1]['class'] ?? 'root')
        . '::'
        . ($backtrace[1]['function'] ?? '\Closuer')
        . " $message [" . Carbon::now()->format('H:i:s.u') . ']'
        . $break;
}

$concurrency = 3;
$client = new Client();

$url = 'http://localhost/wait-random.php';
$generator = function () use ($concurrency, $url) {
    for ($i = 0; $i < $concurrency * 2; $i++) {
        yield new Request('GET', $url, ['Accept' => 'application/json']);
    }
};

$pool = new Pool($client, $generator(), [
    'concurrency' => $concurrency,
    'fulfilled' => function (Response $_, int $index) {
        trace("The \"fulfilled\" callback is now called for request no. $index.");
    },
    'rejected' => function ($_, int $index) {
        trace("The \"rejected\" callback is now called for request no. $index.");
    },
]);
$pool->promise()->wait();
