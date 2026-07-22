<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\MailServer\CreateMailServerRequest;
use App\Http\Requests\MailServer\UpdateMailServerRequest;
use App\Http\Resources\MailServerCollection;
use App\Http\Resources\MailServerResource;
use App\Models\MailServer;
use App\Services\MailServer\MailServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MailServerController extends Controller
{
    public function __construct(private readonly MailServerService $mailServerService) {}

    public function index(Request $request): MailServerCollection
    {
        return MailServerResource::collection(
            $this->mailServerService->paginate(MailServerFiltersData::fromArray($request->query()))
        );
    }

    public function show(MailServer $mailServer): MailServerResource
    {
        return new MailServerResource($mailServer);
    }

    public function store(CreateMailServerRequest $request): JsonResponse
    {
        $context = $request->attributes->get('apiKeyContext');
        return (new MailServerResource(
            $this->mailServerService->create(CreateMailServerData::fromArray($request->validated()), new MailServerMutationContext($context->ownerId(), 'api', $context->id()))
        ))->response()->setStatusCode(201);
    }

    public function update(UpdateMailServerRequest $request, MailServer $mailServer): MailServerResource
    {
        $context = $request->attributes->get('apiKeyContext');
        return new MailServerResource(
            $this->mailServerService->update($mailServer, UpdateMailServerData::fromArray($request->validated()), new MailServerMutationContext($context->ownerId(), 'api', $context->id()))
        );
    }
}
