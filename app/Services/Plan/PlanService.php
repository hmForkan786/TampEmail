<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Actions\Plan\CreatePlanAction;
use App\Actions\Plan\DeletePlanAction;
use App\Actions\Plan\FindPlanByIdAction;
use App\Actions\Plan\FindPlanBySlugAction;
use App\Actions\Plan\PaginatePlansAction;
use App\Actions\Plan\UpdatePlanAction;
use App\DTOs\Plan\CreatePlanData;
use App\DTOs\Plan\PlanFiltersData;
use App\DTOs\Plan\UpdatePlanData;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate plan operations for controllers, API and Filament.
 */
final class PlanService
{
    /**
     * @param CreatePlanAction     $createPlanAction     Create plan action.
     * @param UpdatePlanAction     $updatePlanAction     Update plan action.
     * @param DeletePlanAction     $deletePlanAction     Delete plan action.
     * @param FindPlanByIdAction   $findPlanByIdAction   Find plan by ID action.
     * @param FindPlanBySlugAction $findPlanBySlugAction Find plan by slug action.
     * @param PaginatePlansAction  $paginatePlansAction  Paginate plans action.
     */
    public function __construct(
        private readonly CreatePlanAction $createPlanAction,
        private readonly UpdatePlanAction $updatePlanAction,
        private readonly DeletePlanAction $deletePlanAction,
        private readonly FindPlanByIdAction $findPlanByIdAction,
        private readonly FindPlanBySlugAction $findPlanBySlugAction,
        private readonly PaginatePlansAction $paginatePlansAction,
    ) {}

    /**
     * Create and persist a new plan.
     *
     * @param CreatePlanData $data Validated plan creation data.
     *
     * @return Plan The created plan.
     */
    public function create(CreatePlanData $data): Plan
    {
        return $this->createPlanAction->execute($data);
    }

    /**
     * Update and persist changes to the given plan.
     *
     * @param Plan           $plan The plan to update.
     * @param UpdatePlanData $data Validated plan update data.
     *
     * @return Plan The updated plan.
     */
    public function update(Plan $plan, UpdatePlanData $data): Plan
    {
        return $this->updatePlanAction->execute($plan, $data);
    }

    /**
     * Delete the given plan.
     *
     * @param Plan $plan The plan to delete.
     *
     * @return bool Whether the plan was deleted.
     */
    public function delete(Plan $plan): bool
    {
        return $this->deletePlanAction->execute($plan);
    }

    /**
     * Find a plan by its identifier.
     *
     * @param string $id Plan identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function findById(string $id): ?Plan
    {
        return $this->findPlanByIdAction->execute($id);
    }

    /**
     * Find a plan by its unique slug.
     *
     * @param string $slug Unique plan business identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function findBySlug(string $slug): ?Plan
    {
        return $this->findPlanBySlugAction->execute($slug);
    }

    /**
     * Retrieve a paginated list of plans for the given filters.
     *
     * @param PlanFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated plan results.
     */
    public function paginate(PlanFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginatePlansAction->execute($filters);
    }
}
