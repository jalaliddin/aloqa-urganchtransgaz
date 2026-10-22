<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditAndDocumentTest extends FeatureTestCase
{
    public function test_audit_log_records_who_what_when_and_where(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $user);
        $this->login($user);

        $this->withHeader('User-Agent', 'TestAgent/1.0')->putJson("/api/cable-lines/{$line->id}", ['name' => 'Audit nomi'])->assertOk();

        $log = AuditLog::query()->where('event', 'updated')->where('auditable_type', 'cable_line')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->name, $log->user_name);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame('TestAgent/1.0', $log->user_agent);
        $this->assertNotNull($log->ip_address);
        $this->assertSame('PUT', $log->method);
        $this->assertSame('Audit nomi', $log->new_values['name']);
        $this->assertArrayHasKey('name', $log->old_values);
    }

    public function test_audit_log_cannot_be_modified_or_deleted(): void
    {
        $log = AuditLog::query()->create(['event' => 'test', 'created_at' => now()]);

        try {
            $log->update(['event' => 'changed']);
            $this->fail('Model update should be refused');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Model delete should be refused');
        } catch (LogicException) {
        }

        // The database itself refuses as well (triggers)
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['event' => 'tampered']);
    }

    public function test_audit_log_has_no_write_endpoints_and_requires_permission(): void
    {
        $log = AuditLog::query()->create(['event' => 'test', 'created_at' => now()]);

        $this->login($this->user('mgqb_manager', $this->org()));
        $this->getJson('/api/audit-logs')->assertForbidden();

        $this->login($this->user('auditor'));
        $this->getJson('/api/audit-logs')->assertOk();
        $this->putJson("/api/audit-logs/{$log->id}", ['event' => 'x'])->assertStatus(405);
        $this->deleteJson("/api/audit-logs/{$log->id}")->assertStatus(405);
    }

    public function test_documents_upload_download_and_void(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $user);
        $this->login($user);

        $id = $this->post('/api/documents', [
            'file' => $this->pdfUpload('pasport.pdf'),
            'documentable_type' => 'cable_line', 'documentable_id' => $line->id,
            'type' => 'technical_passport', 'title' => 'Texnik pasport', 'number' => 'TP-1',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonMissingPath('data.path')
            ->json('data.id');

        $document = Document::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $this->pdfUpload()->getContent()), $document->sha256);

        $this->get("/api/documents/{$id}/download")->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertTrue(AuditLog::query()->where('event', 'document_downloaded')->where('auditable_id', $id)->exists());

        $this->getJson("/api/cable-lines/{$line->id}")->assertJsonPath('data.documents_missing', []);

        $this->postJson("/api/documents/{$id}/void", ['reason' => 'Noto\'g\'ri fayl'])->assertOk()->assertJsonPath('data.void_reason', "Noto'g'ri fayl");
        $this->get("/api/documents/{$id}/download")->assertStatus(410);
        $this->assertDatabaseHas('documents', ['id' => $id]); // never physically deleted
    }

    public function test_dangerous_or_disguised_files_are_rejected(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $user);
        $this->login($user);
        $meta = ['documentable_type' => 'cable_line', 'documentable_id' => $line->id, 'type' => 'other', 'title' => 'X'];

        $this->post('/api/documents', [...$meta, 'file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        // A PHP script renamed to .pdf: content-based MIME detection catches it
        $this->post('/api/documents', [...$meta, 'file' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php system($_GET["c"]);')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post('/api/documents', [...$meta, 'documentable_type' => 'user', 'documentable_id' => $user->id, 'file' => $this->pdfUpload()], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, Document::query()->count());
    }

    public function test_documents_of_another_organisation_cannot_be_downloaded(): void
    {
        $org = $this->org();
        $owner = $this->user('mgqb_manager', $org);
        $document = $this->attachDocument($this->line($org, $owner), DocumentType::TechnicalPassport, $owner);

        $this->login($this->user('mgqb_manager', $this->org()));
        $this->get("/api/documents/{$document->id}/download")->assertForbidden();
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');

        $this->post('/api/documents', [
            'file' => $this->pdfUpload(), 'documentable_type' => 'cable_line', 'documentable_id' => $document->documentable_id,
            'type' => 'other', 'title' => 'X',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_write_off_documents_require_financial_access(): void
    {
        $org = $this->org();
        $owner = $this->user('mgqb_manager', $org);
        $document = $this->attachDocument($this->line($org, $owner), DocumentType::WriteOff, $owner);

        $this->login($owner);
        $this->get("/api/documents/{$document->id}/download")->assertForbidden();

        $this->login($this->user('accountant'));
        $this->get("/api/documents/{$document->id}/download")->assertOk();
    }

    public function test_unauthenticated_download_is_refused(): void
    {
        $org = $this->org();
        $owner = $this->user('mgqb_manager', $org);
        $document = $this->attachDocument($this->line($org, $owner), DocumentType::Photo, $owner);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/documents/{$document->id}/download")->assertUnauthorized();
    }
}
