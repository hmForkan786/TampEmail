<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Billing\InvoiceException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Models\BillingInvoice;
use App\Models\User;
use App\Services\Billing\BillingResponseFactory;
use App\Services\Billing\Invoice\BillingHistoryService;
use App\Services\Billing\Invoice\InvoicePdfService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class BillingInvoiceController extends Controller
{
    public function __construct(
        private readonly BillingHistoryService $history,
        private readonly InvoicePdfService $pdfs,
        private readonly BillingResponseFactory $responses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $paginator = $this->history->invoices((string) $user->getKey(), $this->filters($request), (int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($invoice) => $this->history->projectInvoice($invoice))->values(),
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        try {
            $model = $this->history->ownedInvoice($invoice, (string) $this->user($request)->getKey());

            return response()->json(['data' => $this->history->projectInvoice($model)]);
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make('invoice_not_found', 'Invoice not found.', 404);
        }
    }

    public function download(Request $request, string $invoice): Response|JsonResponse
    {
        try {
            $user = $this->user($request);
            $model = $this->history->ownedInvoice($invoice, (string) $user->getKey());
            $pdf = $this->pdfs->render($model, (string) $user->getKey());

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$model->invoice_number.'.pdf"',
            ]);
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make('invoice_not_found', 'Invoice not found.', 404);
        } catch (InvoiceException $exception) {
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        try {
            return $this->history->exportInvoicesCsv((string) $this->user($request)->getKey(), $this->filters($request));
        } catch (Throwable $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    public function payments(Request $request): JsonResponse
    {
        $paginator = $this->history->payments(
            (string) $this->user($request)->getKey(),
            $this->filters($request),
            (int) $request->integer('per_page', 25),
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($tx) => $this->history->projectPayment($tx))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $paginator = $this->history->orders(
            (string) $this->user($request)->getKey(),
            $this->filters($request),
            (int) $request->integer('per_page', 25),
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($order) => $this->history->projectOrder($order))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $filters = $this->filters($request);
        $query = BillingInvoice::query()->with('lineItems')->latest('issued_at')->latest('id');
        if (! empty($filters['invoice_number'])) {
            $query->where('invoice_number', $filters['invoice_number']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        $paginator = $query->cursorPaginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($invoice) => $this->history->projectInvoice($invoice))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    public function adminShow(Request $request, string $invoice): JsonResponse
    {
        $this->authorizeAdmin($request);
        try {
            $model = $this->history->invoiceById($invoice);

            return response()->json(['data' => $this->history->projectInvoice($model)]);
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make('invoice_not_found', 'Invoice not found.', 404);
        }
    }

    public function adminDownload(Request $request, string $invoice): Response|JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        try {
            $model = $this->history->invoiceById($invoice);
            $pdf = $this->pdfs->render($model, (string) $admin->getKey());

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$model->invoice_number.'.pdf"',
            ]);
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make('invoice_not_found', 'Invoice not found.', 404);
        } catch (InvoiceException $exception) {
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->attributes->get('apiKey')->user;
        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            abort(ApiErrorResponse::make('billing_reviewer_required', 'Billing reviewer permission is required.', 403));
        }

        return $user;
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('apiKey')->user;
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return array_filter([
            'invoice_number' => $request->query('invoice_number'),
            'status' => $request->query('status'),
            'provider' => $request->query('provider'),
            'subscription_id' => $request->query('subscription_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
