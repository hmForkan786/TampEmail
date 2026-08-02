<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical metric keys for the Analytics read model.
 * Values must be unique (PHP backed-enum constraint) and are stored in
 * analytics_daily_rollups.metric_key alongside the domain column.
 */
enum AnalyticsMetricKey: string
{
    // Users
    case UsersRegistrations = 'registrations';
    case UsersActive = 'active_users';
    case UsersPremium = 'premium_users';
    case UsersFree = 'free_users';
    case UsersRetentionBps = 'retention_bps';

    // Inbox
    case InboxCreated = 'inboxes_created';
    case InboxActive = 'active_inboxes';
    case InboxExpired = 'inboxes_expired';
    case InboxRenewed = 'inboxes_renewed';

    // Email
    case EmailReceived = 'emails_received';
    case EmailSent = 'emails_sent';
    case EmailReply = 'emails_reply';
    case EmailForward = 'emails_forward';
    case EmailAttachments = 'attachments_used';

    // Billing
    case BillingRevenueMinor = 'revenue_minor';
    case BillingOrders = 'orders';
    case BillingPaid = 'orders_paid';
    case BillingFailed = 'orders_failed';
    case BillingMrrMinor = 'mrr_minor';
    case BillingArrMinor = 'arr_minor';

    // Affiliate
    case AffiliateClicks = 'affiliate_clicks';
    case AffiliateSignups = 'affiliate_signups';
    case AffiliateConversions = 'affiliate_conversions';
    case AffiliateCommissionMinor = 'commission_minor';
    case AffiliateWithdrawals = 'affiliate_withdrawals';

    // Ads
    case AdsImpressions = 'ad_impressions';
    case AdsClicks = 'ad_clicks';
    case AdsCtrBps = 'ad_ctr_bps';
    case AdsRevenueMinor = 'ad_revenue_minor';

    // API
    case ApiRequests = 'api_requests';
    case ApiErrors = 'api_errors';
    case ApiRateLimited = 'api_rate_limited';
    case ApiKeyUsage = 'api_key_usage';
}
