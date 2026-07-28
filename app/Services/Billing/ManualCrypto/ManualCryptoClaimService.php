<?php

declare(strict_types=1);

namespace App\Services\Billing\ManualCrypto;

use App\Enums\BillingOrderStatus;
use App\Enums\ManualCryptoClaimState;
use App\Enums\ManualCryptoEvidenceStatus;
use App\Exceptions\Billing\CheckoutException;
use App\Models\BillingOrder;
use App\Models\ManualCryptoCheckoutSnapshot;
use App\Models\ManualCryptoPaymentClaim;
use App\Models\ManualCryptoReviewEvent;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class ManualCryptoClaimService
{
    public function __construct(private AuditLogWriter $audit) {}

    public function submit(string $orderId, string $userId, string $txid, string $amount, ?UploadedFile $screenshot): ManualCryptoPaymentClaim
    {
        $normalizedTxid = strtolower(trim($txid));
        if (! preg_match('/^[0-9a-f]{64}$/', $normalizedTxid)) {
            throw new CheckoutException('invalid_crypto_txid', 'The TRON transaction hash is invalid.', 422);
        }
        try {
            $units = ManualCryptoAmount::toUnits($amount);
        } catch (\InvalidArgumentException $exception) {
            throw new CheckoutException('invalid_crypto_amount', $exception->getMessage(), 422);
        }

        $path = null;
        if ($screenshot !== null) {
            $path = $screenshot->store('billing/manual-crypto-evidence', (string) config('billing.manual_crypto.evidence_disk', 'local'));
        }

        try {
            return DB::transaction(function () use ($orderId, $userId, $normalizedTxid, $units, $path): ManualCryptoPaymentClaim {
                $order = BillingOrder::query()->lockForUpdate()->find($orderId);
                if (! $order instanceof BillingOrder || (string) $order->user_id !== $userId) {
                    throw new CheckoutException('billing_order_not_found', 'Billing order not found.', 404);
                }
                if ($order->provider !== 'manual_crypto') {
                    throw new CheckoutException('invalid_payment_provider', 'This order does not accept manual crypto claims.', 422);
                }
                if ($order->status === BillingOrderStatus::Paid) {
                    $this->audit->write('manual_crypto.already_paid', $userId, $order);
                    throw new CheckoutException('order_already_paid', 'The order is already paid.');
                }
                if (! in_array($order->status, [BillingOrderStatus::Pending, BillingOrderStatus::Processing], true)
                    || $order->expires_at?->isPast()) {
                    $this->audit->write('manual_crypto.expired_claim', $userId, $order);
                    throw new CheckoutException('checkout_expired', 'The checkout has expired.');
                }
                $snapshot = ManualCryptoCheckoutSnapshot::query()->where('billing_order_id', $order->getKey())->firstOrFail();
                $claim = ManualCryptoPaymentClaim::query()->create([
                    'billing_order_id' => $order->getKey(), 'user_id' => $userId,
                    'checkout_snapshot_id' => $snapshot->getKey(), 'network' => 'TRC20',
                    'txid' => $normalizedTxid, 'submitted_amount_units' => $units,
                    'screenshot_path' => $path, 'state' => ManualCryptoClaimState::Submitted,
                    'evidence_status' => ManualCryptoEvidenceStatus::Submitted, 'submitted_at' => now(),
                ]);
                ManualCryptoReviewEvent::query()->create([
                    'claim_id' => $claim->getKey(), 'actor_id' => $userId,
                    'event' => 'claim_submitted', 'to_state' => ManualCryptoClaimState::Submitted->value,
                    'metadata' => ['screenshot_supplied' => $path !== null], 'created_at' => now(),
                ]);
                $this->audit->write('manual_crypto.claim_submitted', $userId, $claim, newValues: [
                    'network' => 'TRC20', 'txid_fingerprint' => hash('sha256', $normalizedTxid),
                ]);

                return $claim->load(['snapshot', 'reviewEvents']);
            });
        } catch (QueryException $exception) {
            if ($path !== null) {
                Storage::disk((string) config('billing.manual_crypto.evidence_disk', 'local'))->delete($path);
            }
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                $this->audit->write('manual_crypto.duplicate_txid', $userId, null, metadata: ['txid_fingerprint' => hash('sha256', $normalizedTxid)]);
                throw new CheckoutException('duplicate_crypto_txid', 'This transaction hash has already been submitted.', 409);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk((string) config('billing.manual_crypto.evidence_disk', 'local'))->delete($path);
            }
            throw $exception;
        }
    }
}
