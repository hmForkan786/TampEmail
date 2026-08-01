<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookCanonicalizationStrategy: string
{
    case RawBody = 'raw_body';
    case TimestampDotRawBody = 'timestamp_dot_raw_body';
    case TimestampNonceRawBody = 'timestamp_nonce_raw_body';
    case TimestampNewlineRawBody = 'timestamp_newline_raw_body';
    case MethodPathQueryBody = 'method_path_query_body';
    case SortedFormFields = 'sorted_form_fields';
    case ProviderDefined = 'provider_defined';
}
