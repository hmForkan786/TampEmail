<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Advisory rollout recommendation derived from launch metrics vs.
 * configured thresholds. Purely informational — nothing in the codebase
 * automatically disables outbound based on this value; an operator must
 * act on it explicitly.
 */
enum OutboundLaunchRecommendation: string
{
    case Continue = 'continue';
    case Hold = 'hold';
    case Rollback = 'rollback';
}
