<?php

namespace App\Services;

class SeoOpportunities
{
    public const LIMIT = 500;

    /** Rows have already been validated by SeoGoogleReports; missing rows remain unknown. */
    public function analyze(array $queries, array $pages, array $previousQueries, array $previousPages, bool $detail = false): array
    {
        $opportunities = [];
        $comparisons = [];
        foreach (['query' => [$queries, $previousQueries], 'page' => [$pages, $previousPages]] as $dimension => [$currentRows, $previousRows]) {
            $previousByName = array_column($previousRows, null, 'name');
            foreach ($currentRows as $row) {
                $name = $row['name'];
                $current = $this->metrics($row);
                $previous = isset($previousByName[$name]) ? $this->metrics($previousByName[$name]) : null;
                $change = $previous === null ? null : $current['clicks'] - $previous['clicks'];
                if ($detail && $dimension === 'query') {
                    $comparisons[] = ['name' => $name, 'current' => $current, 'previous' => $previous, 'click_change' => $change];
                }
                if ($dimension === 'query' && $current['impressions'] >= 100 && $current['position'] > 10 && $current['position'] <= 20) {
                    $opportunities[] = $this->opportunity('near_page_one', $dimension, $name, $current, $previous,
                        'At least 100 impressions with an average position above 10 and at most 20.',
                        'Review the pages serving this query for search intent, useful content and relevant internal links.');
                }
                if ($dimension === 'page' && $current['impressions'] >= 100 && $current['position'] > 0 && $current['position'] <= 10 && $current['ctr'] < 0.02) {
                    $opportunities[] = $this->opportunity('low_ctr', $dimension, $name, $current, $previous,
                        'At least 100 impressions, an average position from 1 to 10 and a click-through rate below 2%.',
                        'Review the page queries, title and description against search intent and the current search results.');
                }
                if ($previous !== null && $previous['clicks'] >= 10 && $change <= -5 && -$change / $previous['clicks'] >= 0.20) {
                    $opportunities[] = $this->opportunity('declining', $dimension, $name, $current, $previous,
                        'Both periods returned this row: at least 5 fewer clicks and a decline of at least 20%, from at least 10 previous clicks.',
                        'Compare impressions, average position and page changes; check seasonality and search intent before changing content.');
                }
            }
        }
        $priority = ['declining' => 0, 'low_ctr' => 1, 'near_page_one' => 2];
        usort($opportunities, function ($left, $right) use ($priority) {
            return ($priority[$left['type']] <=> $priority[$right['type']])
                ?: ($left['type'] === 'declining' ? $left['click_change'] <=> $right['click_change'] : $right['current']['impressions'] <=> $left['current']['impressions'])
                ?: strcmp($left['dimension'], $right['dimension']) ?: strcmp($left['name'], $right['name']);
        });
        usort($comparisons, fn ($left, $right) => ($right['current']['clicks'] <=> $left['current']['clicks']) ?: strcmp($left['name'], $right['name']));

        return ['opportunities' => array_slice($opportunities, 0, self::LIMIT), 'opportunity_count' => count($opportunities), 'queries' => $comparisons];
    }

    private function metrics(array $row): array
    {
        return array_intersect_key($row, array_flip(['clicks', 'impressions', 'ctr', 'position']));
    }

    private function opportunity(string $type, string $dimension, string $name, array $current, ?array $previous, string $reason, string $action): array
    {
        $change = $previous === null ? null : $current['clicks'] - $previous['clicks'];

        return [
            'id' => hash('sha256', json_encode([$type, $dimension, $name], JSON_THROW_ON_ERROR)),
            'type' => $type, 'dimension' => $dimension, 'name' => $name, 'current' => $current, 'previous' => $previous,
            'click_change' => $change, 'decline_percent' => $type === 'declining' ? -$change / $previous['clicks'] * 100 : null,
            'reason' => $reason, 'action' => $action,
        ];
    }
}
