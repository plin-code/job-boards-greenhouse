<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Greenhouse;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\InvalidResponseException;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the public Greenhouse job board API:
 *
 *   GET https://boards-api.greenhouse.io/v1/boards/{slug}/jobs
 *   { "jobs": [ { "id": 12345, "title": "...", "location": { "name": "..." } } ] }
 *
 *   GET https://boards-api.greenhouse.io/v1/boards/{slug}
 *   { "name": "Acme", "content": "<p>escaped html</p>" }
 *
 * No credentials, no pagination. Boards live in one of two regions and the slug
 * does not say which, so the listing endpoints ask the US host first and fall
 * back to the EU one. The board endpoint behind fetchCompanyDescription() does
 * not: that mirrors the original implementation, which only ever asked the US
 * host for a description.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see GreenhouseServiceProvider} is the only Laravel aware file.
 */
final class GreenhouseClient implements JobBoardClient
{
    public const string API_BASE_URL = 'https://boards-api.greenhouse.io/v1/boards';

    public const string API_BASE_URL_EU = 'https://boards-api.eu.greenhouse.io/v1/boards';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap name and description lookups below.
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl = self::API_BASE_URL,
        private readonly string $euBaseUrl = self::API_BASE_URL_EU,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null.
            $response = $this->listBoard($slug, $this->timeout);

            if ($response->failed()) {
                $this->logger->warning('Greenhouse API request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $jobs = $response->json('jobs');

            if (! is_array($jobs)) {
                $this->logger->warning('Greenhouse API response missing jobs array', [
                    'company_slug' => $slug,
                    'response' => $response->json(),
                ]);

                return [];
            }

            $postings = [];

            foreach ($jobs as $job) {
                if (! is_array($job)) {
                    throw InvalidResponseException::unexpectedShape(
                        $response->url(),
                        'jobs.*',
                        get_debug_type($job),
                    );
                }

                /** @var array<string, mixed> $job */
                $postings[] = $this->mapToDTO($job);
            }

            return $postings;
        } catch (TransportException $e) {
            $this->logger->error('Greenhouse API connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching Greenhouse jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Greenhouse has no account name endpoint, so the board listing doubles as
     * one: the first posting carries "company_name". A board that resolves but
     * lists nothing still validates, and answers with the slug itself.
     *
     * A 404 and a dead connection are deliberately indistinguishable here:
     * neither proves the slug is good.
     */
    public function validateSlug(string $slug): ?string
    {
        try {
            // tryGet() here: this one is silent by contract, so there is no
            // message to keep.
            $response = $this->tryListBoard($slug, $this->lookupTimeout);

            if ($response === null || $response->failed()) {
                return null;
            }

            $jobs = $response->json('jobs');

            if (! is_array($jobs)) {
                return null;
            }

            $first = $jobs[0] ?? null;
            $name = is_array($first) ? ($first['company_name'] ?? null) : null;

            return is_string($name) && $name !== '' ? $name : $slug;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The board endpoint returns an "about us" blurb as HTML. Strip it back to
     * plain text, or null when there is nothing left.
     */
    public function fetchCompanyDescription(string $slug): ?string
    {
        try {
            $response = $this->http
                ->withTimeout($this->lookupTimeout)
                ->tryGet($this->boardEndpoint($this->baseUrl, $slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            $content = $response->json('content');

            if (! is_string($content) || $content === '') {
                return null;
            }

            $text = trim(strip_tags($content));

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * US first, EU second. A non 2xx from the US host is not an error yet, it is
     * the signal to ask the other region; whatever the EU host answers is the
     * result, success or not.
     *
     * @throws TransportException
     */
    private function listBoard(string $slug, float $timeout): Response
    {
        $http = $this->http->withTimeout($timeout);

        $response = $http->get($this->jobsEndpoint($this->baseUrl, $slug));

        if ($response->successful()) {
            return $response;
        }

        return $http->get($this->jobsEndpoint($this->euBaseUrl, $slug));
    }

    /**
     * The same fallback, silently: a transport failure on either host is a null
     * rather than an exception.
     */
    private function tryListBoard(string $slug, float $timeout): ?Response
    {
        $http = $this->http->withTimeout($timeout);

        $response = $http->tryGet($this->jobsEndpoint($this->baseUrl, $slug));

        if ($response !== null && $response->successful()) {
            return $response;
        }

        return $http->tryGet($this->jobsEndpoint($this->euBaseUrl, $slug));
    }

    private function jobsEndpoint(string $baseUrl, string $slug): string
    {
        return sprintf('%s/jobs', $this->boardEndpoint($baseUrl, $slug));
    }

    private function boardEndpoint(string $baseUrl, string $slug): string
    {
        return sprintf('%s/%s', rtrim($baseUrl, '/'), rawurlencode($slug));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapToDTO(array $data): JobPostingDTO
    {
        $id = $data['id'] ?? null;
        $title = $data['title'] ?? null;
        $url = $data['absolute_url'] ?? null;

        return new JobPostingDTO(
            externalId: is_scalar($id) ? (string) $id : '',
            title: is_string($title) && $title !== '' ? $title : 'Untitled Position',
            location: $this->location($data),
            url: is_string($url) ? $url : '',
            // Greenhouse keeps departments on a separate endpoint, so a job in
            // this listing has nothing to map here.
            department: null,
            rawPayload: $data,
        );
    }

    /**
     * "location": { "name": "Paris" }, or an empty object on a remote posting.
     *
     * @param  array<string, mixed>  $data
     */
    private function location(array $data): ?string
    {
        $location = $data['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
