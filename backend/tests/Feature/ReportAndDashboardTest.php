<?php

namespace Tests\Feature;

use App\Models\AuditLog;

class ReportAndDashboardTest extends FeatureTestCase
{
    public function test_dashboard_numbers_come_from_scoped_data(): void
    {
        $own = $this->org();
        $other = $this->org();
        $at = $this->user('at_service');
        $this->line($own, $at, [['laying_type' => 'underground', 'design_length_m' => '1500.00', 'geometry' => $this->lineString()]], ['design_length_m' => '1500.00']);
        $this->line($own, $at, [['laying_type' => 'near_building', 'design_length_m' => '500.00', 'risk_level' => 'high']], ['design_length_m' => '500.00']);
        $this->line($other, $at, overrides: ['design_length_m' => '9999.00']);

        $this->login($this->user('mgqb_manager', $own));
        $kpis = $this->getJson('/api/dashboard')->assertOk()->json('data.kpis');

        $this->assertSame(2, $kpis['lines_count']);
        $this->assertSame('2000.00', $kpis['total_length_m']);
        $this->assertSame(1, $kpis['hazardous_segments']);
        $this->assertSame(2, $kpis['segments_requiring_inspection']);
        $this->assertSame(1, $kpis['segments_without_geometry']);
        $this->assertSame(2, $kpis['incomplete_documents_breakdown']['cable_lines']);
        $this->assertSame('0.00', $kpis['dismantled_length_m']);
        $this->assertNull($kpis['dismantling_percent']);

        $this->login($this->user('management'));
        $this->assertSame(3, $this->getJson('/api/dashboard')->json('data.kpis.lines_count'));
    }

    public function test_reports_list_and_preview_respect_scope(): void
    {
        $own = $this->org();
        $at = $this->user('at_service');
        $this->line($own, $at, overrides: ['name' => 'Ichki liniya']);
        $this->line($this->org(), $at, overrides: ['name' => 'Begona liniya']);
        $this->login($this->user('mgqb_manager', $own));

        $keys = array_column($this->getJson('/api/reports')->assertOk()->json('data'), 'key');
        $this->assertEqualsCanonicalizing([
            'by_organization', 'by_cable_type', 'registry', 'inventory_results', 'dismantling_plan_fact', 'materials',
            'metal_content', 'metal_balance', 'incomplete_documents', 'hazardous_segments', 'left_in_place',
        ], $keys);

        $rows = $this->getJson('/api/reports/registry')->assertOk()->json('data');
        $this->assertSame(['Ichki liniya'], array_column($rows, 'name'));
        $this->assertArrayNotHasKey('initial_cost', $rows[0]);

        $this->getJson('/api/reports/incomplete_documents')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/reports/unknown')->assertNotFound();
    }

    public function test_every_report_opens_with_data(): void
    {
        $at = $this->user('at_service');
        $this->line($this->org(), $at);
        $this->line($this->org(), $at);
        $this->login($this->user('management'));

        foreach (array_column($this->getJson('/api/reports')->assertOk()->json('data'), 'key') as $key) {
            $this->getJson("/api/reports/{$key}")->assertOk();
        }
    }

    public function test_export_to_excel_and_csv(): void
    {
        $org = $this->org();
        $at = $this->user('at_service');
        $this->line($org, $at, overrides: ['name' => "O'zbekcha nom"]);
        $this->login($this->user('accountant'));

        $xlsx = $this->get('/api/reports/registry/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));

        $csv = $this->get('/api/reports/registry/export?format=csv')->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("O'zbekcha nom", $content);
        $this->assertStringContainsString("Boshlang'ich qiymat", $content); // financial column for accountant
        $this->assertTrue(AuditLog::query()->where('event', 'report_exported')->count() === 2);
    }

    public function test_export_requires_permission_and_hides_financial_columns(): void
    {
        $org = $this->org();
        $this->line($org, $this->user('at_service'));

        $this->login($this->user('safety', $org));
        $this->get('/api/reports/registry/export?format=csv')->assertForbidden();

        $this->login($this->user('mgqb_manager', $org));
        $content = $this->get('/api/reports/registry/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString("Boshlang'ich qiymat", $content);
    }

    public function test_meta_endpoint_provides_enum_labels_and_map_settings(): void
    {
        $this->login($this->user('auditor'));

        $this->getJson('/api/meta')->assertOk()
            ->assertJsonPath('data.enums.dismantling_status.6.value', 'completed')
            ->assertJsonPath('data.enums.dismantling_status.6.label', "To'liq bajarilgan")
            ->assertJsonPath('data.map.tile_url', config('aloqa.map.tile_url'))
            ->assertJsonStructure(['data' => ['map' => ['attribution', 'max_zoom', 'default_center', 'wms_layers'], 'tolerance', 'roles']]);
    }

    public function test_map_settings_are_configurable_and_sanitised(): void
    {
        $this->login($this->user('super_admin'));

        $this->putJson('/api/settings/map', [
            'tile_url' => 'https://gis.example.uz/tiles/{z}/{x}/{y}.png',
            'attribution' => '<a href="https://gis.example.uz" onclick="alert(1)">Korporativ GIS</a><script>alert(1)</script>',
            'max_zoom' => 20, 'default_center' => [41.5, 60.6], 'default_zoom' => 9,
            'wms_layers' => [['name' => 'Quvurlar', 'url' => 'https://gis.example.uz/geoserver/wms', 'layers' => 'urtg:pipes', 'transparent' => true]],
        ])->assertOk()->assertJsonPath('data.map.tile_url', 'https://gis.example.uz/tiles/{z}/{x}/{y}.png');

        $attribution = $this->getJson('/api/meta')->json('data.map.attribution');
        $this->assertStringNotContainsString('script', $attribution);
        $this->assertStringNotContainsString('onclick', $attribution);
        $this->assertStringContainsString('Korporativ GIS', $attribution);

        $this->putJson('/api/settings/map', ['tile_url' => 'javascript:alert(1)', 'attribution' => 'x', 'max_zoom' => 1, 'default_center' => [0, 0], 'default_zoom' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('tile_url');

        $this->login($this->user('management'));
        $this->putJson('/api/settings/map', [])->assertForbidden();
    }

    public function test_satellite_layer_defaults_and_can_be_replaced_or_switched_off(): void
    {
        $this->login($this->user('super_admin'));
        $base = ['tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'attribution' => 'OSM', 'max_zoom' => 19, 'default_center' => [41.5, 60.6], 'default_zoom' => 8];

        $this->getJson('/api/meta')->assertOk()
            ->assertJsonPath('data.map.satellite_url', config('aloqa.map.satellite_url'))
            ->assertJsonPath('data.map.satellite_max_native_zoom', config('aloqa.map.satellite_max_native_zoom'))
            ->assertJsonPath('data.map.satellite_labels_url', config('aloqa.map.satellite_labels_url'));

        $this->putJson('/api/settings/map', [...$base,
            'satellite_url' => 'https://gis.example.uz/orto/{z}/{x}/{y}.jpg',
            'satellite_attribution' => '<a href="https://gis.example.uz" onclick="x()">Ortofoto</a><img src=x>',
            'satellite_max_native_zoom' => 20,
            'satellite_labels_url' => null,
        ])->assertOk()
            ->assertJsonPath('data.map.satellite_url', 'https://gis.example.uz/orto/{z}/{x}/{y}.jpg')
            ->assertJsonPath('data.map.satellite_max_native_zoom', 20)
            ->assertJsonPath('data.map.satellite_labels_url', null);
        $attribution = $this->getJson('/api/meta')->json('data.map.satellite_attribution');
        $this->assertStringContainsString('Ortofoto', $attribution);
        $this->assertStringNotContainsString('onclick', $attribution);
        $this->assertStringNotContainsString('<img', $attribution);

        // An empty URL switches the layer off instead of falling back to the default provider
        $this->putJson('/api/settings/map', [...$base, 'satellite_url' => '', 'satellite_attribution' => ''])->assertOk();
        $this->getJson('/api/meta')->assertJsonPath('data.map.satellite_url', null);

        $this->putJson('/api/settings/map', [...$base, 'satellite_url' => 'javascript:alert(1)//{z}{x}{y}', 'satellite_attribution' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('satellite_url');
        $this->putJson('/api/settings/map', [...$base, 'satellite_url' => 'https://gis.example.uz/orto/{z}/{x}/{y}.jpg'])
            ->assertStatus(422)->assertJsonValidationErrors('satellite_attribution');
    }
}
