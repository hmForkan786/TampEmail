<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AffiliateProfile;
use App\Services\Affiliates\AffiliateBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AffiliatesBalanceAuditCommand extends Command
{
    protected $signature = 'affiliates:balance-audit {--json : Emit machine-readable JSON}';

    protected $description = 'Audit affiliate ledger balances for anomalies (negative available/net balances)';

    public function handle(AffiliateBalanceService $balances): int
    {
        $rows = DB::table('affiliate_commission_entries')
            ->select('affiliate_profile_id', 'currency')
            ->distinct()
            ->get();

        $issues = [];

        foreach ($rows as $row) {
            $profile = AffiliateProfile::query()->find($row->affiliate_profile_id);

            if (! $profile instanceof AffiliateProfile) {
                continue;
            }

            $balance = $balances->project($profile, $row->currency);

            if ($balance['available'] < 0 || $balance['net_available'] < 0) {
                $issues[] = [
                    'affiliate_profile_id' => $profile->getKey(),
                    'currency' => $row->currency,
                    'balance' => $balance,
                ];
            }
        }

        $healthy = $issues === [];

        if ($this->option('json')) {
            $this->line(json_encode(['healthy' => $healthy, 'issues' => $issues], JSON_THROW_ON_ERROR));
        } elseif ($healthy) {
            $this->info('No affiliate balance anomalies detected.');
        } else {
            $this->error(count($issues).' affiliate balance anomaly(s) detected.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
