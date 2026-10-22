<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);
        $user = $request->user();
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(DocumentType::class)],
            'documentable_type' => ['nullable', Rule::in(array_keys(DocumentService::ATTACHABLE))],
            'documentable_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'include_voided' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Document::query()->visibleTo($user)
            ->with(['uploader:id,name', 'voider:id,name'])
            ->when(! $user->can('cable_lines.view_financial'), fn ($q) => $q->where('type', '!=', DocumentType::WriteOff->value))
            ->when(! ($filters['include_voided'] ?? false), fn ($q) => $q->whereNull('voided_at'))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['documentable_type'] ?? null, fn ($q, $t) => $q->where('documentable_type', $t))
            ->when($filters['documentable_id'] ?? null, fn ($q, $id) => $q->where('documentable_id', $id))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")->orWhere('number', 'like', "%{$term}%")->orWhere('original_name', 'like', "%{$term}%")))
            ->latest('id');

        return DocumentResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('aloqa.documents.max_kb'), 'extensions:'.implode(',', config('aloqa.documents.allowed_extensions'))],
            'documentable_type' => ['required', Rule::in(array_keys(DocumentService::ATTACHABLE))],
            'documentable_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'title' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $attachable = $this->documents->resolveAttachable($data['documentable_type'], (int) $data['documentable_id']);
        $this->authorize(DocumentService::ATTACHABLE[$data['documentable_type']]['ability'], $attachable);
        abort_unless($request->user()->can('documents.upload'), 403);

        $document = $this->documents->store($request->file('file'), $attachable, $data, $request->user());

        return (new DocumentResource($document->load('uploader:id,name')))->response()->setStatusCode(201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_if($document->isVoided(), 410, 'Hujjat bekor qilingan.');

        return $this->documents->download($document, $request->user());
    }

    public function void(Request $request, Document $document): DocumentResource
    {
        $this->authorize('void', $document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return new DocumentResource($this->documents->void($document, $data['reason'], $request->user())->load('uploader:id,name', 'voider:id,name'));
    }
}
