<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'number' => $this->number,
            'document_date' => $this->document_date?->toDateString(),
            'description' => $this->description,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'documentable_type' => $this->documentable_type,
            'documentable_id' => $this->documentable_id,
            'organization_id' => $this->organization_id,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider?->name),
            'void_reason' => $this->void_reason,
            // Files are only reachable through the authorised download endpoint
            'download_url' => $this->isVoided() ? null : url("/api/documents/{$this->id}/download"),
            'can_void' => $request->user()?->can('void', $this->resource) ?? false,
        ];
    }
}
