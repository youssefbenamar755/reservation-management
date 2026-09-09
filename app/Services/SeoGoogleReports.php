<?php

namespace App\Services;

use App\Models\TrafficConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class SeoGoogleReports
{
    public const ROW_LIMIT = 1000;

    public function __construct(private GoogleReportingClient $google, private SeoOpportunities $opportunities) {}

    public function report(TrafficConnection $connection, string $site, string $start, string $end, ?string $pageUrl = null): array
    {
        [$previousStart, $previousEnd] = $this->previous($start, $end);
        if ($pageUrl !== null && ! $this->validPage($pageUrl)) {
            throw new RuntimeException('Invalid SEO page URL.');
        }
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query';
        $queries = $this->rows($connection, $url, 'query', $start, $end, $pageUrl);
        $previousQueries = $this->rows($connection, $url, 'query', $previousStart, $previousEnd, $pageUrl);
        $pages = $pageUrl === null ? $this->rows($connection, $url, 'page', $start, $end) : [];
        $previousPages = $pageUrl === null ? $this->rows($connection, $url, 'page', $previousStart, $previousEnd) : [];
        $analysis = $this->opportunities->analyze($queries, $pages, $previousQueries, $previousPages, $pageUrl !== null);
        $notes = [
            'Search Console returns the top rows ordered by clicks, with at most 1,000 rows per dimension and period. Privacy rules and internal caps can omit rows; a missing row is unknown, not zero.',
            'Average position is an average across searches, devices and locations, not a fixed ranking or a guaranteed first-page result.',
            'These thresholds are review rules, not predictions or guaranteed traffic gains: queries with at least 100 impressions and average position above 10 to 20; pages with at least 100 impressions, average position 1 to 10 and CTR below 2%; declines of at least 5 clicks and 20% from at least 10 previous clicks.',
            'Periods use finalized web-search data and Pacific dates. Query metrics use property aggregation; page metrics and page-filtered queries use page aggregation. Do not add query and page clicks together.',
        ];
        if (max(count($queries), count($previousQueries), count($pages), count($previousPages)) === self::ROW_LIMIT) {
            $notes[] = 'At least one result reached the 1,000-row limit. Additional opportunities may exist outside these returned rows.';
        }
        if ($analysis['opportunity_count'] > SeoOpportunities::LIMIT) {
            $notes[] = 'The list shows the first 500 prioritized opportunities; the count includes every matching rule in the returned rows.';
        }

        return $analysis + [
            'coverage' => ['queries' => count($queries), 'pages' => count($pages), 'previous_queries' => count($previousQueries),
                'previous_pages' => count($previousPages), 'row_limit' => self::ROW_LIMIT],
            'previous_start_date' => $previousStart, 'previous_end_date' => $previousEnd, 'notes' => $notes,
            // Orchestration uses these exact current rows for the drilldown guard and strips them from browser output.
            'page_names' => array_values(array_filter(array_column($pages, 'name'), fn ($name) => $this->validPage($name))),
        ];
    }

    private function rows(TrafficConnection $connection, string $url, string $dimension, string $start, string $end, ?string $pageUrl = null): array
    {
        $byPage = $dimension === 'page' || $pageUrl !== null;
        $request = [
            'startDate' => $start, 'endDate' => $end, 'dimensions' => [$dimension], 'type' => 'web', 'dataState' => 'final',
            'aggregationType' => $byPage ? 'auto' : 'byProperty', 'rowLimit' => self::ROW_LIMIT, 'startRow' => 0,
        ];
        if ($pageUrl !== null) {
            $request['dimensionFilterGroups'] = [['groupType' => 'and', 'filters' => [
                ['dimension' => 'page', 'operator' => 'equals', 'expression' => $pageUrl],
            ]]];
        }
        $data = $this->google->post($connection, $url, $request);
        // A genuine empty Google object can omit rows and aggregation metadata entirely.
        if (array_diff(array_keys($data), ['rows', 'responseAggregationType', 'metadata'])
            || (array_key_exists('responseAggregationType', $data) && $data['responseAggregationType'] !== ($byPage ? 'byPage' : 'byProperty'))
            || (array_key_exists('rows', $data) && (! is_array($data['rows']) || ! array_is_list($data['rows']) || count($data['rows']) > self::ROW_LIMIT))
            || (array_key_exists('metadata', $data) && (! is_array($data['metadata']) || ($data['metadata'] !== [] && array_is_list($data['metadata']))
                || isset($data['metadata']['first_incomplete_date']) || isset($data['metadata']['first_incomplete_hour'])))) {
            throw new RuntimeException('Invalid Search Console SEO report.');
        }
        $rows = [];
        $names = [];
        foreach ($data['rows'] ?? [] as $row) {
            if (! is_array($row) || ! is_array($row['keys'] ?? null) || ! array_is_list($row['keys']) || count($row['keys']) !== 1) {
                throw new RuntimeException('Invalid Search Console SEO row.');
            }
            $name = $row['keys'][0];
            if (! is_string($name) || $name === '' || strlen($name) > 4096 || ! mb_check_encoding($name, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/', $name) || isset($names[$name]) || ($dimension === 'page' && ! $this->validPage($name, 4096))) {
                throw new RuntimeException('Invalid Search Console SEO dimension.');
            }
            $values = ['name' => $name, 'clicks' => $this->number($row['clicks'] ?? null), 'impressions' => $this->number($row['impressions'] ?? null),
                'ctr' => $this->number($row['ctr'] ?? null, 1), 'position' => $this->number($row['position'] ?? null)];
            if ($values['clicks'] > $values['impressions']) {
                throw new RuntimeException('Invalid Search Console SEO metrics.');
            }
            $rows[] = $values;
            $names[$name] = true;
        }

        return $rows;
    }

    private function number(mixed $value, float|int $max = PHP_INT_MAX): float|int
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > $max) {
            throw new RuntimeException('Invalid Search Console SEO metric.');
        }

        return $value;
    }

    private function validPage(string $url, int $limit = 2048): bool
    {
        if (strlen($url) > $limit || preg_match('/[\x00-\x20\x7F]/', $url) || ! Str::isUrl($url, ['http', 'https'])) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    private function previous(string $start, string $end): array
    {
        foreach ([$start, $end] as $date) {
            if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new RuntimeException('Invalid SEO reporting dates.');
            }
        }
        if ($start > $end) {
            throw new RuntimeException('Invalid SEO reporting range.');
        }
        $from = CarbonImmutable::parse($start, 'UTC');
        $days = (int) $from->diffInDays(CarbonImmutable::parse($end, 'UTC')) + 1;
        if ($days > 93) {
            throw new RuntimeException('Invalid SEO reporting range.');
        }

        return [$from->subDays($days)->toDateString(), $from->subDay()->toDateString()];
    }
}
