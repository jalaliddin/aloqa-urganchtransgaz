<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\DocumentType;
use App\Models\Alert;

class MissingDocumentsScanTest extends FeatureTestCase
{
    public function test_missing_mandatory_documents_raise_and_later_resolve_alerts(): void
    {
        $user = $this->user('at_service');
        $line = $this->line($this->org(), $user);
        $line->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        $fresh = $this->line($this->org(), $user);

        $this->artisan('app:scan-missing-documents')->assertSuccessful();

        $alert = Alert::query()->where('type', AlertType::MissingDocument->value)->where('subject_id', $line->id)->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString($line->registry_number, $alert->message);
        // Grace period: records created today are not flagged yet
        $this->assertFalse(Alert::query()->where('type', AlertType::MissingDocument->value)->where('subject_id', $fresh->id)->exists());

        // Re-running does not duplicate
        $this->artisan('app:scan-missing-documents')->assertSuccessful();
        $this->assertSame(1, Alert::query()->where('type', AlertType::MissingDocument->value)->where('subject_id', $line->id)->count());

        $this->attachDocument($line, DocumentType::TechnicalPassport, $user);
        $this->artisan('app:scan-missing-documents')->assertSuccessful();
        $this->assertSame('resolved', $alert->refresh()->status->value);
    }

    public function test_the_scan_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('app:scan-missing-documents')->assertSuccessful();
    }
}
