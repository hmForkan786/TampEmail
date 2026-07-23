<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class InboxCollection extends ResourceCollection
{
    public $collects = InboxResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return ['meta' => [
            'current_page' => (int) $paginated['current_page'],
            'per_page' => (int) $paginated['per_page'],
            'total' => (int) $paginated['total'],
            'last_page' => (int) $paginated['last_page'],
        ]];
    }
}
