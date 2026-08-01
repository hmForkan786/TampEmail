<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\IdentityHealthCheckService;
use Illuminate\Console\Command;

final class IdentityHealthCommand extends Command
{
    protected $signature = 'identity:health {--json : Emit JSON}';

    protected $description = 'Check Identity Layer health and safe configuration status';

    public function handle(IdentityHealthCheckService $health): int
    {
        $result = $health->check();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result['checks'] as $check) {
            $this->line(sprintf(
                '[%s] %s — %s',
                $check['ok'] ? 'OK' : 'FAIL',
                $check['name'],
                $check['detail'],
            ));
        }

        $this->newLine();
        $this->info('Metrics:');
        foreach ($result['metrics'] as $key => $value) {
            $this->line('  '.$key.': '.(is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
