<?php

declare(strict_types=1);

use PlinCode\JobBoards\Greenhouse\GreenhouseClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    |
    | Greenhouse hosts boards in two regions and a slug does not say which one
    | it belongs to, so the connector asks "base_url" first and falls back to
    | "eu_base_url". Override either to point at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_GREENHOUSE_BASE_URL', GreenhouseClient::API_BASE_URL),

    'eu_base_url' => env('JOB_BOARDS_GREENHOUSE_EU_BASE_URL', GreenhouseClient::API_BASE_URL_EU),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper calls behind validateSlug() and fetchCompanyDescription().
    | Honoured only by PSR-18 clients that implement
    | PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the timeout
    | they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_GREENHOUSE_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_GREENHOUSE_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The public board endpoints need no
    | authentication, so Accept is all Greenhouse asks for.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];
