<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Approval;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\CableType;
use App\Models\DismantlingOrder;
use App\Models\Document;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\MaterialReceipt;
use App\Models\User;
use App\Models\WarehouseTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentService
{
    /** Morph aliases that may carry documents, with the permission needed to attach to them. */
    public const ATTACHABLE = [
        'cable_line' => ['model' => CableLine::class, 'ability' => 'update'],
        'cable_segment' => ['model' => CableSegment::class, 'ability' => 'update'],
        'inspection' => ['model' => Inspection::class, 'ability' => 'attachDocuments'],
        'inspection_item' => ['model' => InspectionItem::class, 'ability' => 'attachDocuments'],
        'dismantling_order' => ['model' => DismantlingOrder::class, 'ability' => 'attachDocuments'],
        'material_receipt' => ['model' => MaterialReceipt::class, 'ability' => 'attachDocuments'],
        'warehouse_transaction' => ['model' => WarehouseTransaction::class, 'ability' => 'attachDocuments'],
        'cable_type' => ['model' => CableType::class, 'ability' => 'attachDocuments'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{type: string, title: string, number?: string|null, document_date?: string|null, description?: string|null}  $meta
     */
    public function store(UploadedFile $file, Model $attachable, array $meta, User $user): Document
    {
        $this->assertSafeFile($file);

        $disk = config('aloqa.documents.disk');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = $file->storeAs('documents/'.now()->format('Y/m'), Str::uuid()->toString().'.'.$extension, $disk);

        return Document::query()->create([
            'organization_id' => $this->organizationIdFor($attachable),
            'documentable_type' => $attachable->getMorphClass(),
            'documentable_id' => $attachable->getKey(),
            'type' => $meta['type'],
            'title' => $meta['title'],
            'number' => $meta['number'] ?? null,
            'document_date' => $meta['document_date'] ?? null,
            'description' => $meta['description'] ?? null,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($this->safeName($file->getClientOriginalName()), 0, 255),
            'mime_type' => $this->detectMime($file),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user->id,
        ]);
    }

    public function download(Document $document, User $user): StreamedResponse
    {
        $this->audit->log('document_downloaded', $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function void(Document $document, string $reason, User $user): Document
    {
        $document->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
        $this->audit->log('document_voided', $document, null, null, $reason);

        return $document;
    }

    public function resolveAttachable(string $type, int $id): Model
    {
        $config = self::ATTACHABLE[$type] ?? null;
        if ($config === null) {
            throw ValidationException::withMessages(['documentable_type' => ["Bu obyektga hujjat biriktirib bo'lmaydi."]]);
        }

        return $config['model']::query()->findOrFail($id);
    }

    public function organizationIdFor(Model $model): ?int
    {
        return match (true) {
            $model instanceof InspectionItem => $model->inspection->organization_id,
            $model instanceof Approval => $model->approvable?->getAttribute('organization_id'),
            default => $model->getAttribute('organization_id'),
        };
    }

    /** Financial documents are visible only to users allowed to see financial data. */
    public static function requiresFinancialAccess(DocumentType $type): bool
    {
        return $type === DocumentType::WriteOff;
    }

    /** MIME type sniffed from the file bytes — never from the client header or the file name. */
    private function detectMime(UploadedFile $file): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
    }

    private function assertSafeFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $this->detectMime($file);

        if (! in_array($extension, config('aloqa.documents.allowed_extensions'), true)
            || ! in_array($mime, config('aloqa.documents.allowed_mimes'), true)) {
            throw ValidationException::withMessages([
                'file' => ["Fayl turi ruxsat etilmagan ({$extension}, {$mime}). Ruxsat etilgan: ".implode(', ', config('aloqa.documents.allowed_extensions')).'.'],
            ]);
        }
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\pL\pN._\- ]+/u', '_', $name) ?? 'file';

        return trim($clean, '. ') ?: 'file';
    }
}
