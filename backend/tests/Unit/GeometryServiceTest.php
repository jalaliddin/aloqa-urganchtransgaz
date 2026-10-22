<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeometryServiceTest extends TestCase
{
    private GeometryService $geometry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geometry = new GeometryService;
    }

    public function test_haversine_length_of_one_degree_of_latitude(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [[60.0, 41.0], [60.0, 42.0]]];

        $this->assertEqualsWithDelta(111195, (float) $this->geometry->lengthMeters($line), 5);
    }

    public function test_feature_collection_of_lines_becomes_multilinestring(): void
    {
        $normalised = $this->geometry->normalize(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[60.1, 41.1], [60.2, 41.2]]]],
            ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[60.3, 41.3], [60.4, 41.4, 120.5]]]],
        ]]);

        $this->assertSame('MultiLineString', $normalised['type']);
        $this->assertSame([60.4, 41.4], $normalised['coordinates'][1][1]); // altitude dropped
    }

    public function test_consecutive_duplicate_points_are_removed_and_precision_limited(): void
    {
        $normalised = $this->geometry->normalize(['type' => 'LineString', 'coordinates' => [
            [60.123456789, 41.1], [60.123456789, 41.1], [60.2, 41.2],
        ]]);

        $this->assertCount(2, $normalised['coordinates']);
        $this->assertSame(60.1234568, $normalised['coordinates'][0][0]);
    }

    public function test_bbox_and_derived_attributes(): void
    {
        $derived = $this->geometry->derivedAttributes(['type' => 'LineString', 'coordinates' => [[60.5, 41.6], [60.1, 41.2], [60.3, 41.9]]]);

        $this->assertSame(41.2, $derived['bbox_min_lat']);
        $this->assertSame(60.5, $derived['bbox_max_lng']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $derived['geometry_length_m']);
    }

    public function test_compare_detects_suspicious_changes(): void
    {
        $a = ['type' => 'LineString', 'coordinates' => [[60.0, 41.0], [60.01, 41.0]]];
        $similar = ['type' => 'LineString', 'coordinates' => [[60.0, 41.0], [60.0102, 41.0]]];
        $longer = ['type' => 'LineString', 'coordinates' => [[60.0, 41.0], [60.02, 41.0]]];

        $this->assertFalse($this->geometry->compare($a, $similar)['suspicious']);
        $this->assertTrue($this->geometry->compare($a, $longer)['suspicious']);
        $this->assertFalse($this->geometry->compare(null, $a)['suspicious']);
    }

    public function test_too_many_vertices_are_rejected(): void
    {
        config(['aloqa.geometry.max_vertices' => 3]);

        $this->expectException(ValidationException::class);
        $this->geometry->normalize(['type' => 'LineString', 'coordinates' => [[60.0, 41.0], [60.1, 41.0], [60.2, 41.0], [60.3, 41.0]]]);
    }
}
