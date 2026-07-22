<?php

declare(strict_types=1);

namespace App\Enums;

enum InboundRoutingCode: string
{
    case Resolved = 'resolved';
    case Expired = 'expired';
    case UnknownDomain = 'unknown_domain';
    case InactiveDomain = 'inactive_domain';
    case UnknownInbox = 'unknown_inbox';
    case InactiveInbox = 'inactive_inbox';
    case InvalidAddress = 'invalid_address';
    case PublicIngressDisabled = 'public_ingress_disabled';
}
