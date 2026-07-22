<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class EmailCollection extends ResourceCollection
{
    /**
     * @var class-string<EmailResource>
     */
    public $collects = EmailResource::class;

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => (int) $paginated['current_page'],
                'per_page' => (int) $paginated['per_page'],
                'total' => (int) $paginated['total'],
                'last_page' => (int) $paginated['last_page'],
            ],
        ];
    }
}
