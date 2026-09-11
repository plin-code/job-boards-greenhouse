<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-greenhouse/main/art/banner.png" alt="Job Boards Greenhouse">
</p>

# Job Boards Greenhouse

<p align="center">
    <a href="https://packagist.org/packages/plin-code/job-boards-greenhouse"><img src="https://img.shields.io/packagist/v/plin-code/job-boards-greenhouse.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-greenhouse"><img src="https://img.shields.io/packagist/php-v/plin-code/job-boards-greenhouse.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-greenhouse"><img src="https://badge.laravel.cloud/badge/plin-code/job-boards-greenhouse?style=flat" alt="Laravel versions"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-greenhouse"><img src="https://img.shields.io/packagist/dt/plin-code/job-boards-greenhouse.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Greenhouse connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public Greenhouse job board API, which needs no credentials and returns a whole board in one request:

```
GET https://boards-api.greenhouse.io/v1/boards/{slug}/jobs
{ "jobs": [ { "id": 12345, "title": "Backend Engineer", "location": { "name": "Paris" }, "absolute_url": "..." } ] }

GET https://boards-api.greenhouse.io/v1/boards/{slug}
{ "name": "Acme", "content": "&lt;p&gt;We build things&lt;/p&gt;" }
```

Greenhouse hosts boards in two regions and the slug does not say which, so both listing calls ask `boards-api.greenhouse.io` first and fall back to `boards-api.eu.greenhouse.io`.

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-greenhouse
```

## Framework agnostic on purpose

`GreenhouseClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Greenhouse\GreenhouseClient;
use PlinCode\JobBoards\Http\HttpClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new GreenhouseClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, the company name
$about = $client->fetchCompanyDescription('acme');  // ?string
```

`GreenhouseServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\Greenhouse\GreenhouseClient;

$client = app(GreenhouseClient::class);

foreach ($client->fetchJobsForCompany('acme') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the base URLs, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-greenhouse-config
```

```php
'base_url'       => env('JOB_BOARDS_GREENHOUSE_BASE_URL', GreenhouseClient::API_BASE_URL),
'eu_base_url'    => env('JOB_BOARDS_GREENHOUSE_EU_BASE_URL', GreenhouseClient::API_BASE_URL_EU),
'timeout'        => env('JOB_BOARDS_GREENHOUSE_TIMEOUT', 30),
'lookup_timeout' => env('JOB_BOARDS_GREENHOUSE_LOOKUP_TIMEOUT', 15),
'headers'        => ['Accept' => 'application/json'],
```

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | Greenhouse field |
| --- | --- |
| `externalId` | `id` cast to a string, `''` when absent or not scalar |
| `title` | `title`, falling back to `'Untitled Position'` when absent, empty or not a string |
| `location` | `location.name`, or `null` when absent, empty or not a string |
| `url` | `absolute_url`, or `''` when absent or not a string |
| `department` | always `null`: Greenhouse keeps departments on a separate endpoint |
| `rawPayload` | the untouched job object |

## Regions

Both `fetchJobsForCompany()` and `validateSlug()` try the US host and, on any non 2xx answer, the EU host. Whatever the EU host says is then the result. `fetchCompanyDescription()` asks the US host only.

## Slug validation

Greenhouse publishes no account name endpoint, so the board listing doubles as one: `validateSlug()` returns `jobs[0].company_name` when it is there, and the slug itself otherwise. A board that resolves but lists nothing still validates and answers with the slug.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| non 2xx status from both regions | `warning` | `Greenhouse API request failed` |
| payload has no `jobs` array | `warning` | `Greenhouse API response missing jobs array` |
| DNS failure, refused connection, timeout | `error` | `Greenhouse API connection error` |
| unreadable body, unexpected shape | `error` | `Unexpected error fetching Greenhouse jobs` |

Every record carries `company_slug`. With no logger passed, a `NullLogger` is used and everything is silent.

`validateSlug()` and `fetchCompanyDescription()` return `null` for every failure and log nothing. That is intentional: neither a 404 nor a dropped connection proves a slug is good, and callers use these to validate user input.

## Timeouts

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so the configured 30 and 15 seconds are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

```json
"require": {
    "plin-code/job-boards-core": "^0.2||^0.3"
}
```

Core is on Packagist, so that constraint is all this package needs: there is no `repositories` block to carry. Do **not** commit a `path` repository pointing at a sibling checkout of core. It resolves against the layout of one machine, and the package then fails to install from a fresh clone anywhere else.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against core's `PlinCode\JobBoards\Testing\FakePsrClient` and boots no framework. `tests/Feature` boots Testbench and covers the service provider only.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
