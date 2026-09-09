<?php

use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\User;
use App\Services\GoogleReportingClient;
use App\Services\TrafficGoogleReports;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'reporting.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret']);
    $this->connection = TrafficConnection::create([
        'user_id' => User::factory()->create()->id, 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(), 'email' => 'reporting@example.test',
        'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    $this->reports = app(TrafficGoogleReports::class);
});

function trafficGaRow(array $dimensions, array $metrics, array $dimensionValues, array $metricValues, array $extra = []): array
{
    return array_replace([
        'dimensionHeaders' => array_map(fn ($name) => ['name' => $name], $dimensions),
        'metricHeaders' => array_map(fn ($name) => ['name' => $name, 'type' => 'TYPE_INTEGER'], $metrics),
        'rows' => [['dimensionValues' => array_map(fn ($value) => ['value' => $value], $dimensionValues), 'metricValues' => array_map(fn ($value) => ['value' => (string) $value], $metricValues)]],
        'rowCount' => 1, 'metadata' => ['timeZone' => 'Africa/Casablanca'],
    ], $extra);
}

function trafficFakeGaBatch(?Closure $modify = null): void
{
    Http::fake(['analyticsdata.googleapis.com/*' => function (Request $request) use ($modify) {
        $reports = [];
        foreach ($request['requests'] as $definition) {
            $dimensions = array_column($definition['dimensions'], 'name');
            $metrics = array_column($definition['metrics'], 'name');
            $previous = $definition['dateRanges'][0]['endDate'] === '2026-08-31';
            $value = match ($dimensions[0] ?? '') {
                'date' => ['20260901'], 'sessionSourceMedium' => ['google / organic'], 'pagePath' => ['/visas'],
                'country' => ['Morocco'], 'deviceCategory' => ['mobile'], default => [],
            };
            $numbers = array_map(fn ($name) => match ($name) {
                'activeUsers' => $previous ? 40 : 100, 'sessions' => $previous ? 50 : 150,
                'screenPageViews' => $previous ? 70 : 250, 'engagementRate' => $previous ? 0.5 : 0.6,
            }, $metrics);
            $report = trafficGaRow($dimensions, $metrics, $value, $numbers);
            $reports[] = $modify ? $modify($report, $dimensions) : $report;
        }

        return Http::response(['reports' => $reports]);
    }]);
}

test('traffic GA4 uses separate period totals and two bounded batches for all dimensions', function () {
    trafficFakeGaBatch();
    $result = $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08');
    expect($result['data']['totals'])->toBe(['users' => 100, 'sessions' => 150, 'views' => 250, 'engagement_rate' => 0.6])
        ->and($result['data']['previous']['users'])->toBe(40)->and($result['data']['timezone'])->toBe('Africa/Casablanca')
        ->and($result['data']['daily'][0])->toBe(['date' => '2026-09-01', 'users' => 100, 'sessions' => 150, 'views' => 250])
        ->and($result['data']['sources'][0]['name'])->toBe('google / organic')
        ->and($result['data']['pages'][0]['name'])->toBe('/visas');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/123456:batchRunReports')
        && count($request['requests']) === 5 && $request['requests'][0]['dimensions'] === []
        && $request['requests'][1]['dateRanges'] === [['startDate' => '2026-08-24', 'endDate' => '2026-08-31']]
        && $request['requests'][2]['limit'] === '93' && $request['requests'][4]['limit'] === '50');
});

test('traffic GA4 totals are never calculated by summing daily users or limited table values', function () {
    trafficFakeGaBatch(fn ($report, $dimensions) => $dimensions ? array_replace($report, [
        'rows' => [['dimensionValues' => $report['rows'][0]['dimensionValues'], 'metricValues' => array_map(fn () => ['value' => '7'], $report['metricHeaders'])]],
    ]) : $report);
    $result = $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08');
    expect($result['data']['totals']['users'])->toBe(100)->and($result['data']['daily'][0]['users'])->toBe(7);
});

test('traffic GA4 surfaces thresholds sampling and limited breakdowns without exposing raw metadata', function () {
    trafficFakeGaBatch(fn ($report, $dimensions) => array_replace($report, [
        'rowCount' => $dimensions ? 100 : 1,
        'metadata' => ['subjectToThresholding' => true, 'dataLossFromOtherRow' => true, 'samplingMetadatas' => [['samplesReadCount' => '12']], 'privateProviderField' => 'must-not-expose'],
    ]));
    $result = $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08');
    expect(implode(' ', $result['notes']))->toContain('privacy thresholds', 'sampled', 'top 50', '(other)')->not->toContain('must-not-expose');
});

test('traffic GA4 validates metric headers ranges and numeric values instead of manufacturing zero reports', function (string $malformed) {
    trafficFakeGaBatch(function ($report) use ($malformed) {
        if ($malformed === 'headers') {
            $report['metricHeaders'][0]['name'] = 'wrong';
        } elseif ($malformed === 'nan') {
            $report['rows'][0]['metricValues'][0]['value'] = 'NaN';
        } elseif ($malformed === 'negative') {
            $report['rows'][0]['metricValues'][0]['value'] = '-1';
        } elseif ($malformed === 'missing') {
            unset($report['rows']);
        } elseif ($malformed === 'restriction') {
            $report['metadata']['schemaRestrictionResponse']['activeMetricRestrictions'] = [['metricName' => 'activeUsers']];
        }

        return $report;
    });
    expect(fn () => $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08'))->toThrow(RuntimeException::class);
})->with(['headers', 'nan', 'negative', 'missing', 'restriction']);

test('traffic GA4 reports a genuine empty result as zero metrics with empty tables', function () {
    trafficFakeGaBatch(fn ($report) => array_replace($report, ['rows' => [], 'rowCount' => 0]));
    $result = $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08');
    expect($result['data']['totals']['users'])->toBe(0)->and($result['data']['daily'])->toBe([]);
});

test('traffic GA4 rejects malformed batch responses and out-of-range trend dates', function (string $type) {
    if ($type === 'batch') {
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['reports' => []])]);
    } else {
        trafficFakeGaBatch(function ($report, $dimensions) {
            if ($dimensions === ['date']) {
                $report['rows'][0]['dimensionValues'][0]['value'] = '20260831';
            }

            return $report;
        });
    }
    expect(fn () => $this->reports->ga4($this->connection, '123456', '2026-09-01', '2026-09-08'))->toThrow(RuntimeException::class);
})->with(['batch', 'date']);

test('traffic Search Console uses finalized Pacific data and dedicated totals instead of top query sums', function () {
    Http::fake(['www.googleapis.com/webmasters/v3/*' => function (Request $request) {
        $row = ['clicks' => $request['dimensions'] ? 7 : 100, 'impressions' => 1000, 'ctr' => 0.1, 'position' => 3.5];
        if ($request['dimensions']) {
            $row['keys'] = [match ($request['dimensions'][0]) {
                'date' => '2026-09-01', 'query' => 'visa reservation', 'page' => 'https://traffic.example.test/visas'
            }];
        }

        return Http::response(['rows' => [$row], 'responseAggregationType' => $request['aggregationType']]);
    }]);
    $result = $this->reports->gsc($this->connection, 'sc-domain:traffic.example.test', '2026-09-01', '2026-09-08');
    expect($result['data']['totals']['clicks'])->toBe(100.0)->and($result['data']['queries'][0]['clicks'])->toBe(7.0)
        ->and($result['data']['daily'][0]['date'])->toBe('2026-09-01')->and(implode(' ', $result['notes']))->toContain('Pacific', 'finalized', 'top 50', 'privacy');
    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sites/sc-domain%3Atraffic.example.test/searchAnalytics/query')
        && $request['dimensions'] === [] && $request['rowLimit'] === 1 && $request['startDate'] === '2026-08-24'
        && $request['endDate'] === '2026-08-31' && $request['dataState'] === 'final' && $request['aggregationType'] === 'byProperty');
    Http::assertSent(fn (Request $request) => $request['dimensions'] === ['page'] && $request['aggregationType'] === 'byPage' && $request['rowLimit'] === 50);
});

test('traffic Search Console distinguishes an empty successful response from malformed and failed reports', function (string $kind) {
    Http::fake(['www.googleapis.com/webmasters/v3/*' => fn (Request $request) => Http::response(
        $kind === 'empty' ? ['responseAggregationType' => $request['aggregationType']] : ($kind === 'empty-object' ? '{}' : ($kind === 'malformed' ? ['rows' => 'invalid'] : ['error' => ['message' => 'private']])),
        $kind === 'failed' ? 403 : 200,
    )]);
    if (in_array($kind, ['empty', 'empty-object'], true)) {
        $result = $this->reports->gsc($this->connection, 'https://traffic.example.test/', '2026-09-01', '2026-09-08');
        expect($result['data']['totals']['clicks'])->toBe(0)->and($result['data']['queries'])->toBe([]);
    } else {
        expect(fn () => $this->reports->gsc($this->connection, 'https://traffic.example.test/', '2026-09-01', '2026-09-08'))->toThrow(RuntimeException::class);
    }
})->with(['empty', 'empty-object', 'malformed', 'failed']);

test('traffic realtime requests only last thirty minutes with no dimensions', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(trafficGaRow([], ['activeUsers'], [], [14]))]);
    expect($this->reports->realtime($this->connection, '123456'))->toBe(14);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/123456:runRealtimeReport')
        && $request['minuteRanges'] === [['startMinutesAgo' => 29, 'endMinutesAgo' => 0]] && $request['metrics'] === [['name' => 'activeUsers']]);
});
