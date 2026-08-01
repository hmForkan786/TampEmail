<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PaymentCapability;
use App\Http\Controllers\Controller;
use App\Jobs\Billing\SyncPaymentStatusJob;
use App\Services\Billing\PaymentGatewayResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BillingReturnController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGatewayResolver $gateways): RedirectResponse
    {
        $gateways->resolve($provider, PaymentCapability::Checkout);
        $orderId = $request->query('order');
        if (is_string($orderId) && preg_match('/^[0-9a-f-]{36}$/i', $orderId) === 1) {
            SyncPaymentStatusJob::dispatch($orderId)
                ->onQueue((string) config('billing.queues.provider_events', 'default'));
        }

        return redirect()->route('mailbox.index', ['payment' => 'pending_verification']);
    }
}
