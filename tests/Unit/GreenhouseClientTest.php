<?php

declare(strict_types=1);

use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Greenhouse\GreenhouseClient;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

const US = 'https://boards-api.greenhouse.io/v1/boards';

const EU = 'https://boards-api.eu.greenhouse.io/v1/boards';

function greenhouseClient(FakePsrClient $fake, ?RecordingLogger $logger = null): GreenhouseClient
{
    return new GreenhouseClient($fake->asHttpClient(), logger: $logger);
}

/**
 * @param  array<array-key, mixed>  $jobs
 */
function withJobs(array $jobs): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson(['jobs' => $jobs]);
}

it('fetches jobs for a valid company slug', function (): void {
    $fake = withJobs([
        [
            'id' => 12345,
            'title' => 'Backend Engineer',
            'location' => ['name' => 'Paris'],
            'absolute_url' => 'https://boards.greenhouse.io/testco/jobs/12345',
        ],
        [
            'id' => 67890,
            'title' => 'Frontend Engineer',
            'location' => ['name' => 'Amsterdam'],
            'absolute_url' => 'https://boards.greenhouse.io/testco/jobs/67890',
        ],
    ]);

    $jobs = greenhouseClient($fake)->fetchJobsForCompany('testco');

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('12345')
        ->and($jobs[0]->title)->toBe('Backend Engineer')
        ->and($jobs[0]->location)->toBe('Paris')
        ->and($jobs[0]->url)->toBe('https://boards.greenhouse.io/testco/jobs/12345')
        ->and($jobs[0]->department)->toBeNull()
        ->and($jobs[1]->externalId)->toBe('67890')
        ->and($fake->lastUri())->toBe(US.'/testco/jobs');
});

it('returns an empty list for an empty jobs array', function (): void {
    expect(greenhouseClient(withJobs([]))->fetchJobsForCompany('testco'))->toBe([]);
});

it('returns an empty list on a failed http response', function (): void {
    // Both regions have to answer: a non 2xx from the US host only means "ask
    // the EU host", so the EU response is the one that ends up logged.
    $fake = (new FakePsrClient)
        ->respondWith(500, 'Server Error')
        ->respondWith(500, 'EU Server Error');
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['Greenhouse API request failed'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe([
            'company_slug' => 'broken',
            'status' => 500,
            'body' => 'EU Server Error',
        ])
        ->and($fake->uris())->toBe([US.'/broken/jobs', EU.'/broken/jobs']);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['Greenhouse API connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('returns an empty list when the jobs key is missing', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['error' => 'not found']);
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->fetchJobsForCompany('invalid'))->toBe([])
        ->and($logger->messages())->toBe(['Greenhouse API response missing jobs array'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context']['response'])->toBe(['error' => 'not found']);
});

it('handles a missing location gracefully', function (): void {
    $jobs = greenhouseClient(withJobs([
        [
            'id' => 12345,
            'title' => 'Remote Engineer',
            'location' => [],
            'absolute_url' => 'https://boards.greenhouse.io/testco/jobs/12345',
        ],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBeNull();
});

it('drops a location that is not an object, or whose name is empty or not a string', function (): void {
    $jobs = greenhouseClient(withJobs([
        ['id' => 1, 'title' => 'A', 'location' => 'Paris'],
        ['id' => 2, 'title' => 'B', 'location' => ['name' => '']],
        ['id' => 3, 'title' => 'C', 'location' => ['name' => 42]],
        ['id' => 4, 'title' => 'D'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBeNull()
        ->and($jobs[1]->location)->toBeNull()
        ->and($jobs[2]->location)->toBeNull()
        ->and($jobs[3]->location)->toBeNull();
});

it('stores the raw payload in the dto', function (): void {
    $jobs = greenhouseClient(withJobs([
        [
            'id' => 12345,
            'title' => 'Engineer',
            'location' => ['name' => 'Berlin'],
            'absolute_url' => 'https://example.com',
            'metadata' => ['key' => 'value'],
        ],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->rawPayload)->toHaveKey('metadata');
});

it('falls back to an empty id and url and a placeholder title', function (): void {
    $jobs = greenhouseClient(withJobs([
        ['id' => 12345],
        ['id' => ['nested'], 'title' => '', 'absolute_url' => ['nested']],
        ['id' => 7, 'title' => 99],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->externalId)->toBe('12345')
        ->and($jobs[0]->title)->toBe('Untitled Position')
        ->and($jobs[0]->url)->toBe('')
        ->and($jobs[1]->externalId)->toBe('')
        ->and($jobs[1]->title)->toBe('Untitled Position')
        ->and($jobs[1]->url)->toBe('')
        ->and($jobs[2]->title)->toBe('Untitled Position');
});

it('falls back to the eu region when the us board 404s', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson(['jobs' => [['id' => 1, 'title' => 'EU Engineer']]]);

    $jobs = greenhouseClient($fake)->fetchJobsForCompany('euco');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('EU Engineer')
        ->and($fake->uris())->toBe([US.'/euco/jobs', EU.'/euco/jobs']);
});

it('returns an empty list when the body is not json', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<html>maintenance</html>');
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Greenhouse jobs'])
        ->and($logger->levels())->toBe(['error']);
});

it('returns an empty list when a jobs entry is not an object', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['jobs' => ['not-an-object']]);
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Greenhouse jobs']);
});

it('validates a valid slug and returns the company name from the first job', function (): void {
    $fake = withJobs([
        [
            'id' => 12345,
            'title' => 'Engineer',
            'company_name' => 'TestCo Inc',
            'location' => ['name' => 'Paris'],
            'absolute_url' => 'https://boards.greenhouse.io/testco/jobs/12345',
        ],
    ]);

    expect(greenhouseClient($fake)->validateSlug('testco'))->toBe('TestCo Inc')
        ->and($fake->lastUri())->toBe(US.'/testco/jobs');
});

it('returns the slug when company_name is missing, empty or not a string', function (): void {
    expect(greenhouseClient(withJobs([['id' => 1, 'title' => 'Engineer']]))->validateSlug('testco'))->toBe('testco')
        ->and(greenhouseClient(withJobs([['company_name' => '']]))->validateSlug('testco'))->toBe('testco')
        ->and(greenhouseClient(withJobs([['company_name' => 42]]))->validateSlug('testco'))->toBe('testco')
        ->and(greenhouseClient(withJobs(['not-an-object']))->validateSlug('testco'))->toBe('testco');
});

it('returns the slug when the board validates with an empty jobs array', function (): void {
    expect(greenhouseClient(withJobs([]))->validateSlug('testco'))->toBe('testco');
});

it('returns null for an invalid slug', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWith(404, 'Not Found');

    expect(greenhouseClient($fake)->validateSlug('nonexistent'))->toBeNull()
        ->and($fake->uris())->toBe([US.'/nonexistent/jobs', EU.'/nonexistent/jobs']);
});

it('returns null for slug validation on a connection error, silently', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(greenhouseClient($fake, $logger)->validateSlug('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('returns null when the validate slug response has no jobs key or is not json', function (): void {
    $missingKey = (new FakePsrClient)
        ->respondWithJson(['error' => 'not found'])
        ->respondWithJson(['error' => 'not found']);

    expect(greenhouseClient($missingKey)->validateSlug('testco'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->respondWith(200, 'not json'))->validateSlug('testco'))->toBeNull();
});

it('validates a slug against the eu region when the us board 404s', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson(['jobs' => [['company_name' => 'EU Co']]]);

    expect(greenhouseClient($fake)->validateSlug('euco'))->toBe('EU Co')
        ->and($fake->uris())->toBe([US.'/euco/jobs', EU.'/euco/jobs']);
});

it('fetches the company description and strips its html', function (): void {
    $fake = (new FakePsrClient)->respondWithJson([
        'name' => 'Test Co',
        'content' => '<p>Greenhouse company description.</p>',
    ]);

    expect(greenhouseClient($fake)->fetchCompanyDescription('test-co'))->toBe('Greenhouse company description.')
        // The board endpoint rather than the jobs one, and the US region only.
        ->and($fake->lastUri())->toBe(US.'/test-co');
});

it('returns null from fetchCompanyDescription when it is absent, empty, not a string or all markup', function (): void {
    expect(greenhouseClient((new FakePsrClient)->respondWithJson(['name' => 'Test Co']))->fetchCompanyDescription('testco'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->respondWithJson(['content' => '']))->fetchCompanyDescription('testco'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->respondWithJson(['content' => 42]))->fetchCompanyDescription('testco'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->respondWithJson(['content' => '<p> </p>']))->fetchCompanyDescription('testco'))->toBeNull();
});

it('returns null from fetchCompanyDescription when the board is unreachable', function (): void {
    $logger = new RecordingLogger;

    expect(greenhouseClient((new FakePsrClient)->respondWith(404, 'Not Found'))->fetchCompanyDescription('nope'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->respondWith(200, 'not json'))->fetchCompanyDescription('nope'))->toBeNull()
        ->and(greenhouseClient((new FakePsrClient)->throwNetworkError(), $logger)->fetchCompanyDescription('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('asks for 30 seconds when listing and 15 when looking up', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(['jobs' => []])
        ->respondWithJson(['jobs' => []])
        ->respondWithJson(['content' => 'Hello']);

    $client = greenhouseClient($fake);
    $client->fetchJobsForCompany('testco');
    $client->validateSlug('testco');
    $client->fetchCompanyDescription('testco');

    expect($fake->appliedTimeouts)->toBe([30.0, 15.0, 15.0]);
});

it('percent encodes the slug in every url', function (): void {
    $jobs = withJobs([]);
    greenhouseClient($jobs)->fetchJobsForCompany('a b/../c');

    $board = (new FakePsrClient)->respondWithJson(['content' => 'x']);
    greenhouseClient($board)->fetchCompanyDescription('a b/../c');

    expect($jobs->lastUri())->toBe(US.'/a%20b%2F..%2Fc/jobs')
        ->and($board->lastUri())->toBe(US.'/a%20b%2F..%2Fc');
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new GreenhouseClient($fake->asHttpClient()))->fetchJobsForCompany('testco'))->toBe([]);
});

it('accepts custom base urls', function (): void {
    $fake = (new FakePsrClient)
        ->respondWith(404, 'Not Found')
        ->respondWithJson(['jobs' => []]);

    (new GreenhouseClient($fake->asHttpClient(), 'https://us.test/boards/', 'https://eu.test/boards/'))
        ->fetchJobsForCompany('testco');

    expect($fake->uris())->toBe(['https://us.test/boards/testco/jobs', 'https://eu.test/boards/testco/jobs']);
});
