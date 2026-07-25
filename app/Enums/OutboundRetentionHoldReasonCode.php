<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fixed set of legal/security hold reason codes for outbound messages.
 *
 * Deliberately not free text: audit metadata and reporting must stay
 * bounded and never carry investigator-authored narrative content.
 */
enum OutboundRetentionHoldReasonCode: string
{
    case LegalHold = 'legal_hold';
    case SecurityInvestigation = 'security_investigation';
    case RegulatoryRequest = 'regulatory_request';
    case Other = 'other';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::LegalHold->value => 'Legal hold',
            self::SecurityInvestigation->value => 'Security investigation',
            self::RegulatoryRequest->value => 'Regulatory request',
            self::Other->value => 'Other',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
