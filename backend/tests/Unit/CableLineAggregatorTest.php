<?php

namespace Tests\Unit;

use App\Models\CableSegment;
use App\Services\CableLineAggregator;
use Tests\TestCase;

class CableLineAggregatorTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $segments */
    private function aggregate(array $segments): array
    {
        return (new CableLineAggregator)->aggregate(collect($segments)->map(fn ($attributes) => new CableSegment($attributes)));
    }

    public function test_worst_known_condition_and_highest_risk_win(): void
    {
        $result = $this->aggregate([
            ['technical_condition' => 'good', 'risk_level' => 'low'],
            ['technical_condition' => 'poor', 'risk_level' => 'critical'],
            ['technical_condition' => 'unknown', 'risk_level' => 'medium'],
        ]);

        $this->assertSame('poor', $result['technical_condition']->value);
        $this->assertSame('critical', $result['risk_level']->value);
    }

    public function test_line_is_never_marked_not_in_use_when_only_part_is_not_used(): void
    {
        $this->assertSame('partially_used', $this->aggregate([['usage_status' => 'in_use'], ['usage_status' => 'not_in_use']])['usage_status']->value);
        $this->assertSame('partially_used', $this->aggregate([['usage_status' => 'unknown'], ['usage_status' => 'not_in_use']])['usage_status']->value);
        $this->assertSame('not_in_use', $this->aggregate([['usage_status' => 'not_in_use'], ['usage_status' => 'not_in_use']])['usage_status']->value);
        $this->assertSame('in_use', $this->aggregate([['usage_status' => 'unknown'], ['usage_status' => 'in_use']])['usage_status']->value);
    }

    public function test_dismantling_status_aggregation(): void
    {
        $this->assertSame('partially_completed', $this->aggregate([['dismantling_status' => 'completed'], ['dismantling_status' => 'not_planned']])['dismantling_status']->value);
        $this->assertSame('in_progress', $this->aggregate([['dismantling_status' => 'in_progress'], ['dismantling_status' => 'completed']])['dismantling_status']->value);
        $this->assertSame('awaiting_review', $this->aggregate([['dismantling_status' => 'awaiting_review'], ['dismantling_status' => 'approved']])['dismantling_status']->value);
    }

    public function test_measured_length_and_geometry_flags(): void
    {
        $result = $this->aggregate([
            ['measured_length_m' => '100.10', 'geometry' => ['type' => 'LineString', 'coordinates' => [[1, 1], [2, 2]]]],
            ['measured_length_m' => '0.20', 'complexity_flags' => ['under_road']],
            ['measured_length_m' => null],
        ]);

        $this->assertSame('100.30', $result['measured_length_m']);
        $this->assertTrue($result['has_geometry']);
        $this->assertTrue($result['is_complex']);
    }
}
