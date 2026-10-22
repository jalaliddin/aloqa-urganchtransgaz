<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

class GeometryMapTest extends FeatureTestCase
{
    public function test_geometry_is_validated_saved_and_measured(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $response = $this->putJson("/api/cable-segments/{$segment->id}/geometry", [
            'geometry' => ['type' => 'LineString', 'coordinates' => [[60.60, 41.50], [60.61, 41.50], [60.61, 41.51]]],
            'source' => 'manual',
            'note' => 'Xaritada chizildi',
        ])->assertOk();

        $length = (float) $response->json('data.geometry_length_m');
        // ~834 m east + ~1112 m north
        $this->assertEqualsWithDelta(1946, $length, 15);
        $response->assertJsonPath('data.geometry_source', 'manual');

        $segment->refresh();
        $this->assertEqualsWithDelta(41.5, (float) $segment->bbox_min_lat, 0.0001);
        $this->assertTrue($segment->cableLine->has_geometry);
        $this->assertTrue(AuditLog::query()->where('event', 'geometry_updated')->where('auditable_id', $segment->id)->exists());
    }

    public function test_feature_and_multilinestring_inputs_are_accepted(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $this->putJson("/api/cable-segments/{$segment->id}/geometry", [
            'geometry' => ['type' => 'Feature', 'properties' => [], 'geometry' => [
                'type' => 'MultiLineString', 'coordinates' => [[[60.6, 41.5], [60.62, 41.5]], [[60.63, 41.5], [60.64, 41.51]]],
            ]],
            'source' => 'gps',
        ])->assertOk()->assertJsonPath('data.geometry.type', 'MultiLineString');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidGeometries(): iterable
    {
        yield 'polygon' => [['type' => 'Polygon', 'coordinates' => [[[60.6, 41.5], [60.7, 41.5], [60.6, 41.6], [60.6, 41.5]]]], "Qo'llab-quvvatlanmaydigan"];
        yield 'single point' => [['type' => 'LineString', 'coordinates' => [[60.6, 41.5]]], 'kamida 2'];
        yield 'not numbers' => [['type' => 'LineString', 'coordinates' => [['a', 'b'], [60.6, 41.5]]], 'sonlar juftligi'];
        yield 'out of range' => [['type' => 'LineString', 'coordinates' => [[200, 41.5], [60.6, 41.5]]], 'oraliqdan tashqarida'];
        yield 'outside region' => [['type' => 'LineString', 'coordinates' => [[10.0, 50.0], [10.1, 50.1]]], 'hududidan tashqarida'];
        yield 'swapped lat/lng' => [['type' => 'LineString', 'coordinates' => [[41.5, 60.6], [41.51, 60.61]]], 'tartibi almashgan'];
        yield 'zero length' => [['type' => 'LineString', 'coordinates' => [[60.6, 41.5], [60.6, 41.5]]], 'nol uzunlik'];
    }

    #[DataProvider('invalidGeometries')]
    public function test_invalid_geometry_is_rejected(array $geometry, string $expectedMessage): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $response = $this->putJson("/api/cable-segments/{$segment->id}/geometry", ['geometry' => $geometry, 'source' => 'manual'])
            ->assertStatus(422)->assertJsonValidationErrors('geometry');
        $this->assertStringContainsString($expectedMessage, $response->json('errors.geometry.0'));
        $this->assertNull($segment->refresh()->geometry);
    }

    public function test_demo_source_cannot_be_submitted_via_api(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $this->putJson("/api/cable-segments/{$segment->id}/geometry", ['geometry' => $this->lineString(), 'source' => 'demo'])
            ->assertStatus(422)->assertJsonValidationErrors('source');
    }

    public function test_suspicious_geometry_change_raises_an_alert(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $this->putJson("/api/cable-segments/{$segment->id}/geometry", ['geometry' => $this->lineString(60.6, 41.5), 'source' => 'manual'])->assertOk();
        $this->assertFalse(Alert::query()->where('type', AlertType::SuspiciousGeometryChange->value)->exists());

        // Moved ~40 km away
        $this->putJson("/api/cable-segments/{$segment->id}/geometry", ['geometry' => $this->lineString(61.1, 41.5), 'source' => 'manual'])->assertOk();
        $this->assertTrue(Alert::query()->where('type', AlertType::SuspiciousGeometryChange->value)->where('subject_id', $segment->id)->exists());
    }

    public function test_map_features_follow_registry_filters_and_bbox(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $near = $this->line($org, $user, [['laying_type' => 'underground', 'geometry' => $this->lineString(60.60, 41.50), 'risk_level' => 'high']]);
        $far = $this->line($org, $user, [['laying_type' => 'underground', 'geometry' => $this->lineString(61.50, 42.00)]]);
        $noGeometry = $this->line($org, $user);
        $this->login($user);

        $all = $this->getJson('/api/map/features')->assertOk()->assertJsonPath('type', 'FeatureCollection');
        $this->assertCount(2, $all->json('features'));

        $inView = $this->getJson('/api/map/features?bbox=60.5,41.4,60.7,41.6')->assertOk()->json('features');
        $this->assertCount(1, $inView);
        $this->assertSame($near->id, $inView[0]['properties']['line_id']);
        $this->assertSame($near->registry_number, $inView[0]['properties']['registry_number']);

        $this->getJson('/api/map/features?risk_level[]=high')->assertOk()->assertJsonCount(1, 'features');
        $this->getJson('/api/map/features?q='.$far->registry_number)->assertOk()->assertJsonPath('features.0.properties.line_id', $far->id);

        $missing = $this->getJson('/api/map/missing-geometry')->assertOk()->json('data');
        $this->assertSame([$noGeometry->id], array_column($missing, 'line_id'));
    }

    public function test_geojson_export_and_import_round_trip(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $line = $this->line($org, $user, [['laying_type' => 'underground', 'geometry' => $this->lineString(60.60, 41.50)], ['laying_type' => 'duct']]);
        [$withGeometry, $target] = $line->segments()->get()->all();
        $this->login($user);

        $export = $this->get('/api/map/export')->assertOk();
        $this->assertStringContainsString('application/geo+json', $export->headers->get('content-type'));
        $collection = json_decode($export->streamedContent(), true);
        $this->assertSame($withGeometry->code, $collection['features'][0]['properties']['segment_code']);

        $payload = ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['segment_code' => $target->code], 'geometry' => $this->lineString(60.62, 41.52)],
        ]];

        $this->postJson('/api/map/import', ['geojson' => $payload, 'source' => 'gps', 'source_note' => 'GPS 2026', 'dry_run' => true])
            ->assertOk()->assertJsonPath('applied', false)->assertJsonPath('report.0.status', 'ok');
        $this->assertNull($target->refresh()->geometry);

        $this->postJson('/api/map/import', ['geojson' => $payload, 'source' => 'gps', 'source_note' => 'GPS 2026'])
            ->assertOk()->assertJsonPath('applied', true);
        $this->assertSame('gps', $target->refresh()->geometry_source->value);
        $this->assertSame('GPS 2026', $target->geometry_source_note);
    }

    public function test_import_is_all_or_nothing_and_reports_errors(): void
    {
        $org = $this->org();
        $user = $this->user('at_service');
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $file = UploadedFile::fake()->createWithContent('trassa.geojson', json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['segment_code' => $segment->code], 'geometry' => $this->lineString()],
            ['type' => 'Feature', 'properties' => ['segment_code' => 'NOT-EXISTS'], 'geometry' => $this->lineString()],
            ['type' => 'Feature', 'properties' => ['segment_code' => $segment->code], 'geometry' => ['type' => 'Point', 'coordinates' => [60.6, 41.5]]],
        ]]));

        $response = $this->post('/api/map/import', ['file' => $file, 'source' => 'import', 'source_note' => 'TEST'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('applied', false);

        $this->assertSame(['ok', 'error', 'error'], array_column($response->json('report'), 'status'));
        $this->assertNull($segment->refresh()->geometry);
    }

    public function test_user_without_geometry_permission_cannot_import(): void
    {
        $this->login($this->user('auditor'));

        $this->postJson('/api/map/import', ['geojson' => ['type' => 'FeatureCollection', 'features' => []], 'source' => 'gps', 'source_note' => 'x'])
            ->assertForbidden();
    }
}
