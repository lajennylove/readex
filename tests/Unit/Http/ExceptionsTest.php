<?php

use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

function httpResponse(int $status, string $body): Response
{
    return new Response(new Psr7Response($status, [], $body));
}

it('uses the MangaDex error detail, then the title, then the HTTP reason', function (string $body, string $message) {
    $error = ApiError::fromResponse(httpResponse(400, $body));

    expect($error->status)->toBe(400)
        ->and($error->getCode())->toBe(400)
        ->and($error->getMessage())->toBe($message);
})->with([
    'detail' => ['{"result":"error","errors":[{"title":"validation","detail":"limit too big"}]}', 'MangaDex HTTP 400: limit too big'],
    'title' => ['{"result":"error","errors":[{"title":"validation"}]}', 'MangaDex HTTP 400: validation'],
    'not json' => ['<html>bad</html>', 'MangaDex HTTP 400: Bad Request'],
]);

it('carries the Busy answer so jobs know how long to wait', function () {
    $busy = new Busy(1_792_756_860.0, BusyReason::Banned);
    $e = new RequestDeferred($busy);

    expect($e->busy)->toBe($busy)
        ->and($e->getMessage())->toBe('MangaDex request deferred (banned) until 1792756860');
});
