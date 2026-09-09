<?php

use App\Services\SeoOpportunities;

function seoOpportunityRow(string $name, array $metrics = []): array
{
    return ['name' => $name] + $metrics + ['clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 12];
}

test('SEO rules include exact threshold boundaries and only the intended dimensions', function () {
    $rows = [
        seoOpportunityRow('below impressions', ['impressions' => 99]),
        seoOpportunityRow('position ten', ['position' => 10]),
        seoOpportunityRow('above ten', ['position' => 10.01]),
        seoOpportunityRow('position twenty', ['position' => 20]),
        seoOpportunityRow('above twenty', ['position' => 20.01]),
    ];
    $pages = [
        seoOpportunityRow('low ctr', ['ctr' => 0.0199, 'position' => 10]),
        seoOpportunityRow('ctr two percent', ['ctr' => 0.02, 'position' => 5]),
        seoOpportunityRow('zero position', ['ctr' => 0.01, 'position' => 0]),
        seoOpportunityRow('few page impressions', ['ctr' => 0.01, 'position' => 1, 'impressions' => 99]),
        seoOpportunityRow('page beyond ten', ['ctr' => 0.01, 'position' => 10.01]),
    ];
    $result = (new SeoOpportunities)->analyze($rows, $pages, [], []);
    expect(array_column($result['opportunities'], 'name'))->toBe(['low ctr', 'above ten', 'position twenty'])
        ->and(array_column($result['opportunities'], 'type'))->toBe(['low_ctr', 'near_page_one', 'near_page_one'])
        ->and($result['opportunity_count'])->toBe(3)->and($result['queries'])->toBe([]);
    foreach ($result['opportunities'] as $opportunity) {
        expect($opportunity['previous'])->toBeNull()->and($opportunity['click_change'])->toBeNull()->and($opportunity['decline_percent'])->toBeNull();
    }
});

test('SEO decline rules require every loss threshold and a returned row in both periods', function (int $previous, int $current, bool $declining) {
    $result = (new SeoOpportunities)->analyze([seoOpportunityRow('query', ['clicks' => $current, 'position' => 5])], [],
        [seoOpportunityRow('query', ['clicks' => $previous])], []);
    expect($result['opportunity_count'])->toBe($declining ? 1 : 0);
    if ($declining) {
        expect($result['opportunities'][0]['type'])->toBe('declining')
            ->and($result['opportunities'][0]['click_change'])->toBe($current - $previous)
            ->and($result['opportunities'][0]['decline_percent'])->toBe(($previous - $current) / $previous * 100);
    }
})->with([[25, 20, true], [24, 20, false], [100, 81, false], [9, 0, false], [10, 5, true], [20, 25, false], [10, 0, true]]);

test('SEO comparisons never fabricate losses for missing rows and distinguish a returned zero row', function () {
    $current = [seoOpportunityRow('new query'), seoOpportunityRow('returned zero', ['clicks' => 0, 'position' => 5])];
    $previous = [seoOpportunityRow('missing current', ['clicks' => 100]), seoOpportunityRow('returned zero', ['clicks' => 10])];
    $result = (new SeoOpportunities)->analyze($current, [], $previous, [], true);
    expect(array_column($result['opportunities'], 'name'))->toBe(['returned zero', 'new query'])
        ->and(array_column($result['queries'], 'name'))->toBe(['new query', 'returned zero'])
        ->and($result['queries'][0]['previous'])->toBeNull()->and($result['queries'][0]['click_change'])->toBeNull()
        ->and($result['queries'][1]['click_change'])->toBe(-10)
        ->and((new SeoOpportunities)->analyze([], [], $previous, [])['opportunities'])->toBe([]);
});

test('SEO priorities and stable identities are deterministic across provider row order with overlapping rules', function () {
    $queries = [seoOpportunityRow('same name', ['clicks' => 5, 'impressions' => 200]), seoOpportunityRow('bigger loss', ['clicks' => 3, 'position' => 5])];
    $previousQueries = [seoOpportunityRow('same name', ['clicks' => 10]), seoOpportunityRow('bigger loss', ['clicks' => 20])];
    $pages = [seoOpportunityRow('same name', ['clicks' => 5, 'impressions' => 300, 'ctr' => 0.01, 'position' => 5])];
    $previousPages = [seoOpportunityRow('same name', ['clicks' => 10])];
    $service = new SeoOpportunities;
    $result = $service->analyze($queries, $pages, $previousQueries, $previousPages, true);
    expect($service->analyze(array_reverse($queries), $pages, array_reverse($previousQueries), $previousPages, true))->toBe($result)
        ->and(array_column($result['opportunities'], 'type'))->toBe(['declining', 'declining', 'declining', 'low_ctr', 'near_page_one'])
        ->and(array_column($result['opportunities'], 'dimension'))->toBe(['query', 'page', 'query', 'page', 'query'])
        ->and(count(array_unique(array_column($result['opportunities'], 'id'))))->toBe(5);
    $changedMetrics = $queries;
    $changedMetrics[0]['impressions'] = 400;
    $newIds = array_column($service->analyze($changedMetrics, $pages, $previousQueries, $previousPages)['opportunities'], 'id');
    expect($newIds)->toBe(array_column($result['opportunities'], 'id'));
});

test('SEO opportunity caps preserve the full match count and prioritize higher impression rows', function () {
    $rows = array_map(fn ($index) => seoOpportunityRow('query '.$index, ['impressions' => 100 + $index]), range(1, 600));
    $result = (new SeoOpportunities)->analyze($rows, [], [], [], true);
    expect($result['opportunities'])->toHaveCount(500)->and($result['opportunity_count'])->toBe(600)
        ->and($result['opportunities'][0]['name'])->toBe('query 600')->and($result['opportunities'][499]['name'])->toBe('query 101')
        ->and($result['queries'])->toHaveCount(600);
});
