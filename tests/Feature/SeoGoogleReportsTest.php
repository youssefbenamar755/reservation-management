<?php

use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\User;
use App\Services\GoogleReportingClient;
use App\Services\SeoGoogleReports;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'seo-fixture.apps.googleusercontent.com', 'client_secret' => 'synthetic-client-secret']);
    $this->connection = TrafficConnection::create([
        'user_id' => User::factory()->create()->id, 'email' => 'reporter@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(), 'access_token' => 'synthetic-access-token',
        'refresh_token' => 'synthetic-refresh-token', 'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    $this->reports = app(SeoGoogleReports::class);
});

function seoGoogleRow(string $name, array $values = []): array
{
    return ['keys' => [$name]] + $values + ['clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 12];
}

test('SEO overview uses four bounded finalized GSC requests with equal inclusive previous dates and correct aggregation', function () {
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => function (Request $request) {
        $page = $request['dimensions'] === ['page'];
        $previous = $request['startDate'] === '2026-08-24';
        $row = seoGoogleRow($page ? 'https://seo.example.test/visa?lang=fr' : 'visa reservation', ['clicks' => $previous ? 10 : 5]);

        return Http::response(['rows' => [$row], 'responseAggregationType' => $page ? 'byPage' : 'byProperty']);
    }]);
    $result = $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-09-01', '2026-09-08');
    expect($result['previous_start_date'])->toBe('2026-08-24')->and($result['previous_end_date'])->toBe('2026-08-31')
        ->and($result['coverage'])->toBe(['queries' => 1, 'pages' => 1, 'previous_queries' => 1, 'previous_pages' => 1, 'row_limit' => 1000])
        ->and($result['queries'])->toBe([])->and($result['page_names'])->toBe(['https://seo.example.test/visa?lang=fr'])
        ->and($result['opportunity_count'])->toBe(3)
        ->and(implode(' ', $result['notes']))->toContain('top rows ordered by clicks', 'Privacy', 'unknown, not zero', 'not a fixed ranking', 'review rules', 'Pacific');
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => $request['dimensions'] === ['query'] && $request['aggregationType'] === 'byProperty'
        && $request['startDate'] === '2026-08-24' && $request['endDate'] === '2026-08-31');
    Http::assertSent(fn (Request $request) => $request['dimensions'] === ['page'] && $request['aggregationType'] === 'auto');
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toBe('https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aseo.example.test/searchAnalytics/query')
            ->and($request['rowLimit'])->toBe(1000)->and($request['startRow'])->toBe(0)
            ->and($request['dataState'])->toBe('final')->and($request['type'])->toBe('web')
            ->and($request->data())->not->toHaveKey('dimensionFilterGroups');
    }
});

test('SEO page drilldown uses two exact equals-filtered query reports and never fetches a page URL', function () {
    $pageUrl = 'https://seo.example.test/path?quote=%22&lang=fr';
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => function (Request $request) {
        $rows = $request['startDate'] === '2026-09-01' ? [seoGoogleRow('returned query'), seoGoogleRow('new query')]
            : [seoGoogleRow('returned query', ['clicks' => 10]), seoGoogleRow('missing current', ['clicks' => 100])];

        return Http::response(['rows' => $rows, 'responseAggregationType' => 'byPage']);
    }]);
    $result = $this->reports->report($this->connection, 'https://seo.example.test/', '2026-09-01', '2026-09-08', $pageUrl);
    expect($result['queries'])->toHaveCount(2)->and($result['queries'][0]['name'])->toBe('new query')
        ->and($result['queries'][0]['previous'])->toBeNull()->and($result['queries'][0]['click_change'])->toBeNull()
        ->and($result['queries'][1]['click_change'])->toBe(-5)->and($result['coverage']['pages'])->toBe(0)
        ->and($result['page_names'])->toBe([])->and(array_column($result['opportunities'], 'name'))->not->toContain('missing current');
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toBe('https://www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fseo.example.test%2F/searchAnalytics/query')
            ->and($request['aggregationType'])->toBe('auto')->and($request['dimensions'])->toBe(['query'])
            ->and($request['dimensionFilterGroups'])->toBe([['groupType' => 'and', 'filters' => [
                ['dimension' => 'page', 'operator' => 'equals', 'expression' => $pageUrl],
            ]]]);
    }
});

test('SEO accepts genuine empty Google envelopes without manufacturing opportunities or comparisons', function (string $kind) {
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => fn (Request $request) => Http::response(match ($kind) {
        'object' => '{}', 'rows' => ['rows' => []], default => ['responseAggregationType' => $request['aggregationType'] === 'auto' ? 'byPage' : 'byProperty'],
    })]);
    $result = $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-03-01', '2026-03-01');
    expect($result['opportunities'])->toBe([])->and($result['queries'])->toBe([])->and($result['opportunity_count'])->toBe(0)
        ->and($result['previous_start_date'])->toBe('2026-02-28')->and($result['previous_end_date'])->toBe('2026-02-28');
    Http::assertSentCount(4);
})->with(['object', 'rows', 'aggregation']);

test('SEO strictly rejects malformed GSC envelopes and rows instead of treating them as empty', function (string $kind) {
    $row = seoGoogleRow('fixture query');
    $data = match ($kind) {
        'unexpected envelope' => ['message' => 'provider diagnostic'],
        'error' => ['error' => null],
        'null rows' => ['rows' => null],
        'nonlist rows' => ['rows' => ['query' => $row]],
        'aggregation' => ['responseAggregationType' => 'byPage', 'rows' => [$row]],
        'null aggregation' => ['responseAggregationType' => null],
        'partial metadata' => ['metadata' => ['first_incomplete_date' => '2026-09-01']],
        'invalid metadata' => ['metadata' => 'provider diagnostic'],
        'missing metric' => ['rows' => [array_diff_key($row, ['clicks' => true])]],
        'string metric' => ['rows' => [array_replace($row, ['clicks' => '5'])]],
        'negative metric' => ['rows' => [array_replace($row, ['clicks' => -1])]],
        'boolean metric' => ['rows' => [array_replace($row, ['clicks' => true])]],
        'ctr range' => ['rows' => [array_replace($row, ['ctr' => 1.1])]],
        'clicks range' => ['rows' => [array_replace($row, ['clicks' => 101])]],
        'keys count' => ['rows' => [array_replace($row, ['keys' => ['one', 'two']])]],
        'keys type' => ['rows' => [array_replace($row, ['keys' => 'query'])]],
        'empty name' => ['rows' => [array_replace($row, ['keys' => ['']])]],
        'control name' => ['rows' => [array_replace($row, ['keys' => ["invalid\nquery"]])]],
        'duplicate' => ['rows' => [$row, $row]],
        default => ['rows' => array_fill(0, 1001, $row)],
    };
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => Http::response($data)]);
    expect(fn () => $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-09-01', '2026-09-08'))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with(['unexpected envelope', 'error', 'null rows', 'nonlist rows', 'aggregation', 'null aggregation', 'partial metadata', 'invalid metadata',
    'missing metric', 'string metric', 'negative metric', 'boolean metric', 'ctr range', 'clicks range', 'keys count', 'keys type', 'empty name', 'control name', 'duplicate', 'limit']);

test('SEO never permits unsafe page values into private drilldown eligibility', function (string $page) {
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => fn (Request $request) => Http::response(
        $request['dimensions'] === ['page'] ? ['rows' => [seoGoogleRow($page)], 'responseAggregationType' => 'byPage'] : '{}',
    )]);
    expect(fn () => $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-09-01', '2026-09-08'))->toThrow(RuntimeException::class);
    Http::assertSentCount(3);
})->with(['javascript:alert(1)', 'https://user:secret@seo.example.test/path', '/relative', 'https://seo.example.test/'.str_repeat('x', 4096)]);

test('SEO input bounds reject invalid dates or page filters before a provider request', function (string $start, string $end, ?string $page) {
    expect(fn () => $this->reports->report($this->connection, 'sc-domain:seo.example.test', $start, $end, $page))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    ['2026-02-30', '2026-03-01', null], ['2026-09-02', '2026-09-01', null], ['2026-01-01', '2026-09-01', null],
    ['2026-09-01', '2026-09-08', 'https://user@seo.example.test/'], ['2026-09-01', '2026-09-08', 'file:///private.pdf'],
]);

test('SEO reports top row and opportunity caps with full match count without extra API requests', function () {
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => fn (Request $request) => Http::response(
        $request['dimensions'] === ['query'] && $request['startDate'] === '2026-09-01'
            ? ['rows' => array_map(fn ($index) => seoGoogleRow('query '.$index), range(1, 1000)), 'responseAggregationType' => 'byProperty'] : '{}',
    )]);
    $result = $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-09-01', '2026-09-08');
    expect($result['coverage']['queries'])->toBe(1000)->and($result['opportunity_count'])->toBe(1000)->and($result['opportunities'])->toHaveCount(500)
        ->and(implode(' ', $result['notes']))->toContain('reached the 1,000-row limit', 'first 500');
    Http::assertSentCount(4);
});

test('SEO preserves international and long canonical names while limiting drilldown eligibility separately', function () {
    $longPage = 'https://seo.example.test/'.str_repeat('path', 550);
    $unicodePage = 'https://réservation.example.test/حجز?lang=fr';
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => fn (Request $request) => Http::response([
        'rows' => $request['dimensions'] === ['page']
            ? [seoGoogleRow($longPage, ['ctr' => 0.01, 'position' => 5]), seoGoogleRow($unicodePage, ['ctr' => 0.01, 'position' => 5])]
            : [seoGoogleRow('réservation حجز للفيزا')],
        'responseAggregationType' => $request['aggregationType'] === 'auto' ? 'byPage' : 'byProperty',
    ])]);
    $result = $this->reports->report($this->connection, 'sc-domain:seo.example.test', '2026-09-01', '2026-09-08');
    expect($result['coverage']['pages'])->toBe(2)->and($result['page_names'])->toBe([$unicodePage])
        ->and(array_column($result['opportunities'], 'name'))->toContain($longPage, $unicodePage, 'réservation حجز للفيزا');
    Http::assertSentCount(4);
});

test('SEO previous periods use inclusive calendar days across leap days DST and year boundaries', function (string $start, string $end, string $previousStart, string $previousEnd) {
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => Http::response('{}')]);
    $result = $this->reports->report($this->connection, 'sc-domain:seo.example.test', $start, $end);
    expect($result['previous_start_date'])->toBe($previousStart)->and($result['previous_end_date'])->toBe($previousEnd);
    Http::assertSent(fn (Request $request) => $request['startDate'] === $previousStart && $request['endDate'] === $previousEnd);
    Http::assertSentCount(4);
})->with([
    ['2024-03-01', '2024-03-01', '2024-02-29', '2024-02-29'],
    ['2024-02-28', '2024-03-01', '2024-02-25', '2024-02-27'],
    ['2026-03-07', '2026-03-09', '2026-03-04', '2026-03-06'],
    ['2026-10-31', '2026-11-02', '2026-10-28', '2026-10-30'],
    ['2026-01-01', '2026-01-02', '2025-12-30', '2025-12-31'],
]);
