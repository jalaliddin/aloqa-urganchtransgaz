<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        $shared = $document->documentable_type === 'cable_type';
        if (! $user->can('documents.view') || (! $shared && ! $user->canAccessOrganization($document->organization_id))) {
            return false;
        }

        return ! DocumentService::requiresFinancialAccess($document->type) || $user->can('cable_lines.view_financial');
    }

    /** Uploader may void their own mistaken upload; others need documents.void. Always audited. */
    public function void(User $user, Document $document): bool
    {
        if ($document->isVoided() || ! $user->canAccessOrganization($document->organization_id)) {
            return false;
        }

        return $user->can('documents.void') || ($document->uploaded_by === $user->id && $user->can('documents.upload'));
    }
}
