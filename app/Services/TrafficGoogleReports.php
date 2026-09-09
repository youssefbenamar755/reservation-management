<?php

namespace App\Services;

use App\Models\TrafficConnection;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Bounded reports only: totals are independent of the top-50 dimension tables. */
class TrafficGoogleReports
{
    public function __construct(private GoogleReportingClient $google) {}

    public function ga4(TrafficConnection $connection, string $property, string $start, string $end): array
    {
        [$previousStart, $previousEnd] = $this->previous($start, $end);
        $metrics = ['activeUsers', 'sessions', 'screenPageViews', 'engagementRate'];
        $definitions = [
            'totals' => $this->gaRequest($start, $end, [], $metrics, 1),
            'previous' => $this->gaRequest($previousStart, $previousEnd, [], $metrics, 1),
            'daily' => $this->gaRequest($start, $end, ['date'], array_slice($metrics, 0, 3), 93, false),
            'sources' => $this->gaRequest($start, $end, ['sessionSourceMedium'], ['sessions'], 50),
            'pages' => $this->gaRequest($start, $end, ['pagePath'], ['screenPageViews'], 50),
            'countries' => $this->gaRequest($start, $end, ['country'], ['activeUsers'], 50),
            'devices' => $this->gaRequest($start, $end, ['deviceCategory'], ['activeUsers'], 50),
        ];
        $reports = [];
        $notes = [];
        $timezone = null;
        foreach (array_chunk($definitions, 5, true) as $batch) {
            $response = $this->google->post($connection, "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:batchRunReports", ['requests' => array_values($batch)]);
            if (! isset($response['reports']) || ! is_array($response['reports']) || count($response['reports']) !== count($batch)) {
                throw new RuntimeException('Invalid Analytics report.');
            }
            foreach (array_keys($batch) as $index => $name) {
                $report = $response['reports'][$index];
                if (! is_array($report)) {
                    throw new RuntimeException('Invalid Analytics report.');
                }
                $definition = $batch[$name];
                $reports[$name] = $this->gaRows($report, array_column($definition['dimensions'], 'name'), array_column($definition['metrics'], 'name'), (int) $definition['limit']);
                $metadata = $report['metadata'] ?? [];
                if (! is_array($metadata)) {
                    throw new RuntimeException('Invalid Analytics metadata.');
                }
                if (is_string($metadata['timeZone'] ?? null)) {
                    $timezone = $this->text($metadata['timeZone'], 100);
                }
                if (! empty($metadata['subjectToThresholding'])) {
                    $notes[] = 'Google Analytics may omit data below its privacy thresholds.';
                }
                if (! empty($metadata['dataLossFromOtherRow'])) {
                    $notes[] = 'Google Analytics grouped some high-cardinality values into an (other) row.';
                }
                if (! empty($metadata['samplingMetadatas'])) {
                    $notes[] = 'Google Analytics sampled some report data.';
                }
                if (! empty($metadata['schemaRestrictionResponse']['activeMetricRestrictions'])) {
                    throw new RuntimeException('Analytics metrics are restricted.');
                }
                if (! empty($metadata['emptyReason'])) {
                    $notes[] = 'Google Analytics reported an empty result. Check the property, date coverage, and privacy thresholds.';
                }
                if ((int) ($report['rowCount'] ?? 0) > count($reports[$name])) {
                    $notes[] = 'Google Analytics breakdowns show the top 50 values; totals include the full reporting period.';
                }
            }
        }
        $totals = fn ($rows) => [
            'users' => (int) ($rows[0]['activeUsers'] ?? 0), 'sessions' => (int) ($rows[0]['sessions'] ?? 0),
            'views' => (int) ($rows[0]['screenPageViews'] ?? 0), 'engagement_rate' => $rows[0]['engagementRate'] ?? 0,
        ];

        return ['data' => [
            'totals' => $totals($reports['totals']), 'previous' => $totals($reports['previous']),
            'daily' => array_map(function ($row) use ($start, $end) {
                $raw = $row['date'];
                if (! preg_match('/^\d{8}$/D', $raw) || ! checkdate((int) substr($raw, 4, 2), (int) substr($raw, 6, 2), (int) substr($raw, 0, 4))) {
                    throw new RuntimeException('Invalid Analytics date.');
                }
                $date = substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
                if ($date < $start || $date > $end) {
                    throw new RuntimeException('Analytics date outside requested range.');
                }

                return ['date' => $date, 'users' => (int) $row['activeUsers'], 'sessions' => (int) $row['sessions'], 'views' => (int) $row['screenPageViews']];
            }, $reports['daily']),
            'sources' => array_map(fn ($row) => ['name' => $row['sessionSourceMedium'], 'sessions' => (int) $row['sessions']], $reports['sources']),
            'pages' => array_map(fn ($row) => ['name' => $row['pagePath'], 'views' => (int) $row['screenPageViews']], $reports['pages']),
            'countries' => array_map(fn ($row) => ['name' => $row['country'], 'users' => (int) $row['activeUsers']], $reports['countries']),
            'devices' => array_map(fn ($row) => ['name' => $row['deviceCategory'], 'users' => (int) $row['activeUsers']], $reports['devices']),
            'timezone' => $timezone,
        ], 'notes' => array_values(array_unique($notes))];
    }

    private function gaRequest(string $start, string $end, array $dimensions, array $metrics, int $limit, bool $descending = true): array
    {
        return [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => array_map(fn ($name) => ['name' => $name], $dimensions),
            'metrics' => array_map(fn ($name) => ['name' => $name], $metrics),
            'limit' => (string) $limit,
            'orderBys' => $dimensions ? [$descending
                ? ['metric' => ['metricName' => $metrics[0]], 'desc' => true]
                : ['dimension' => ['dimensionName' => $dimensions[0]], 'desc' => false]] : [],
        ];
    }

    private function gaRows(array $report, array $dimensions, array $metrics, int $limit): array
    {
        if (array_column($report['dimensionHeaders'] ?? [], 'name') !== $dimensions
            || array_column($report['metricHeaders'] ?? [], 'name') !== $metrics
            || ! is_array($report['rows'] ?? []) || ! array_is_list($report['rows'] ?? [])
            || count($report['rows'] ?? []) > $limit) {
            throw new RuntimeException('Invalid Analytics rows.');
        }
        $rows = [];
        foreach ($report['rows'] ?? [] as $row) {
            if (! is_array($row) || count($row['dimensionValues'] ?? []) !== count($dimensions)
                || count($row['metricValues'] ?? []) !== count($metrics)) {
                throw new RuntimeException('Invalid Analytics values.');
            }
            $value = [];
            foreach ($dimensions as $index => $name) {
                $value[$name] = $this->text($row['dimensionValues'][$index]['value'] ?? null);
            }
            foreach ($metrics as $index => $name) {
                $value[$name] = $this->number($row['metricValues'][$index]['value'] ?? null, $name === 'engagementRate' ? 1 : PHP_INT_MAX);
            }
            $rows[] = $value;
        }
        if ((int) ($report['rowCount'] ?? count($rows)) > 0 && ! $rows) {
            throw new RuntimeException('Analytics rows are missing.');
        }

        return $rows;
    }

    public function gsc(TrafficConnection $connection, string $site, string $start, string $end): array
    {
        [$previousStart, $previousEnd] = $this->previous($start, $end);
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query';
        $query = function (string $from, string $to, array $dimensions, int $limit) use ($connection, $url) {
            $aggregation = in_array('page', $dimensions, true) ? 'byPage' : 'byProperty';
            $data = $this->google->post($connection, $url, [
                'startDate' => $from, 'endDate' => $to, 'dimensions' => $dimensions,
                'type' => 'web', 'dataState' => 'final', 'aggregationType' => $aggregation,
                'rowLimit' => $limit, 'startRow' => 0,
            ]);
            // A valid zero-row response may omit both rows and aggregation metadata.
            if (isset($data['error']) || (isset($data['responseAggregationType']) && $data['responseAggregationType'] !== $aggregation) || ! is_array($data['rows'] ?? [])
                || ! array_is_list($data['rows'] ?? []) || count($data['rows'] ?? []) > $limit) {
                throw new RuntimeException('Invalid Search Console report.');
            }
            $rows = [];
            foreach ($data['rows'] ?? [] as $row) {
                if (! is_array($row) || count($row['keys'] ?? []) !== count($dimensions)) {
                    throw new RuntimeException('Invalid Search Console values.');
                }
                $values = [
                    'clicks' => $this->number($row['clicks'] ?? null), 'impressions' => $this->number($row['impressions'] ?? null),
                    'ctr' => $this->number($row['ctr'] ?? null, 1), 'position' => $this->number($row['position'] ?? null),
                ];
                if ($dimensions) {
                    $values['name'] = $this->text($row['keys'][0]);
                }
                $rows[] = $values;
            }

            return $rows;
        };
        $zero = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        $totals = $query($start, $end, [], 1);
        $previous = $query($previousStart, $previousEnd, [], 1);
        $daily = $query($start, $end, ['date'], 93);
        $queries = $query($start, $end, ['query'], 50);
        $pages = $query($start, $end, ['page'], 50);
        usort($daily, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['data' => [
            'totals' => $totals[0] ?? $zero, 'previous' => $previous[0] ?? $zero,
            'daily' => array_map(function ($row) use ($start, $end) {
                $date = $row['name'];
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) || $date < $start || $date > $end) {
                    throw new RuntimeException('Invalid Search Console date.');
                }

                return ['date' => $date, 'clicks' => $row['clicks'], 'impressions' => $row['impressions']];
            }, $daily),
            'queries' => $queries, 'pages' => $pages,
        ], 'notes' => ['Search Console uses Pacific dates and finalized data; recent days can be delayed. Search query and page tables show the top 50 rows, and privacy rules can omit queries.']];
    }

    public function realtime(TrafficConnection $connection, string $property): int
    {
        $report = $this->google->post($connection, "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:runRealtimeReport", [
            'metrics' => [['name' => 'activeUsers']], 'minuteRanges' => [['startMinutesAgo' => 29, 'endMinutesAgo' => 0]], 'limit' => '1',
        ]);
        $rows = $this->gaRows($report, [], ['activeUsers'], 1);

        return (int) ($rows[0]['activeUsers'] ?? 0);
    }

    private function previous(string $start, string $end): array
    {
        $from = CarbonImmutable::parse($start, 'UTC');
        $days = (int) $from->diffInDays(CarbonImmutable::parse($end, 'UTC')) + 1;

        return [$from->subDays($days)->toDateString(), $from->subDay()->toDateString()];
    }

    private function number(mixed $value, float|int $max = PHP_INT_MAX): float|int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > $max) {
            throw new RuntimeException('Invalid reporting metric.');
        }

        return (float) $value;
    }

    private function text(mixed $value, int $limit = 1000): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Invalid reporting dimension.');
        }

        return mb_substr($value, 0, $limit);
    }
}
