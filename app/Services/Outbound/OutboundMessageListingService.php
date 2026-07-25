<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Owner-scoped, filterable outbound message listing.
 *
 * Purely a read/query concern: it never mutates state and always scopes
 * to the given owner, mirroring the ownership rule enforced everywhere
 * else in the outbound stack (`where('user_id', $owner->getKey())`).
 */
final class OutboundMessageListingService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $owner, array $filters = []): LengthAwarePaginator
    {
        $query = OutboundMessage::query()
            ->where('user_id', $owner->getKey())
            ->with(['inbox:id,full_address,display_name,domain_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate($this->resolvePerPage($filters['per_page'] ?? null))
            ->withQueryString();
    }

    /**
     * @param  Builder<OutboundMessage>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $state = $this->stringFilter($filters['state'] ?? null);
        if ($state !== null && OutboundMessageState::tryFrom($state) instanceof OutboundMessageState) {
            $query->where('state', $state);
        }

        $operation = $this->stringFilter($filters['operation'] ?? null);
        if ($operation !== null && OutboundOperation::tryFrom($operation) instanceof OutboundOperation) {
            $query->where('operation', $operation);
        }

        $inboxId = $this->stringFilter($filters['inbox_id'] ?? null);
        if ($inboxId !== null) {
            $query->where('inbox_id', $inboxId);
        }

        $dateFrom = $this->dateFilter($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom->startOfDay());
        }

        $dateTo = $this->dateFilter($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo->endOfDay());
        }

        $recipient = $this->stringFilter($filters['recipient'] ?? null);
        if ($recipient !== null) {
            $needle = mb_strtolower($recipient);
            $query->whereRaw('LOWER(to_recipients) LIKE ?', ['%'.$needle.'%']);
        }

        if (array_key_exists('has_attachments', $filters) && $filters['has_attachments'] !== null && $filters['has_attachments'] !== '') {
            $hasAttachments = filter_var($filters['has_attachments'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($hasAttachments === true) {
                $query->whereNotNull('attachment_ids')->whereJsonLength('attachment_ids', '>', 0);
            } elseif ($hasAttachments === false) {
                $query->where(function (Builder $inner): void {
                    $inner->whereNull('attachment_ids')->orWhereJsonLength('attachment_ids', 0);
                });
            }
        }
    }

    private function stringFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function dateFilter(mixed $value): ?Carbon
    {
        $string = $this->stringFilter($value);

        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePerPage(mixed $value): int
    {
        $perPage = filter_var($value, FILTER_VALIDATE_INT);

        if ($perPage === false || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
