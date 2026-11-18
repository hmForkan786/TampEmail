<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureEncoding: string
{
    case Hex = 'hex';
    case Base64 = 'base64';
    case Base64Url = 'base64url';
    case Raw = 'raw';
}
