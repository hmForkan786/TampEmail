<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settings\SettingsHealthCheckService;
use Illuminate\Console\Command;

final class SettingsHealthCommand extends Command
{
    protected $signature = 'settings:health {--json : Emit JSON}';

    protected $description = 'Check User Settings Center health and safe configuration status';

    public function handle(SettingsHealthCheckService $health): int
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
