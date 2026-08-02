<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;

/**
 * Declares the metric catalogue for dashboards / reports (read-model only).
 */
final class AnalyticsMetricCatalog
{
    /**
     * @return list<array{domain: AnalyticsDomain, metric: AnalyticsMetricKey, label: string}>
     */
    public function all(): array
    {
        return [
            ['domain' => AnalyticsDomain::Users, 'metric' => AnalyticsMetricKey::UsersRegistrations, 'label' => 'Registrations'],
            ['domain' => AnalyticsDomain::Users, 'metric' => AnalyticsMetricKey::UsersActive, 'label' => 'Active users'],
            ['domain' => AnalyticsDomain::Users, 'metric' => AnalyticsMetricKey::UsersPremium, 'label' => 'Premium users'],
            ['domain' => AnalyticsDomain::Users, 'metric' => AnalyticsMetricKey::UsersFree, 'label' => 'Free users'],
            ['domain' => AnalyticsDomain::Users, 'metric' => AnalyticsMetricKey::UsersRetentionBps, 'label' => 'Retention (bps)'],

            ['domain' => AnalyticsDomain::Inbox, 'metric' => AnalyticsMetricKey::InboxCreated, 'label' => 'Inboxes created'],
            ['domain' => AnalyticsDomain::Inbox, 'metric' => AnalyticsMetricKey::InboxActive, 'label' => 'Active inboxes'],
            ['domain' => AnalyticsDomain::Inbox, 'metric' => AnalyticsMetricKey::InboxExpired, 'label' => 'Expired inboxes'],
            ['domain' => AnalyticsDomain::Inbox, 'metric' => AnalyticsMetricKey::InboxRenewed, 'label' => 'Renewed inboxes'],

            ['domain' => AnalyticsDomain::Email, 'metric' => AnalyticsMetricKey::EmailReceived, 'label' => 'Emails received'],
            ['domain' => AnalyticsDomain::Email, 'metric' => AnalyticsMetricKey::EmailSent, 'label' => 'Emails sent'],
            ['domain' => AnalyticsDomain::Email, 'metric' => AnalyticsMetricKey::EmailReply, 'label' => 'Replies'],
            ['domain' => AnalyticsDomain::Email, 'metric' => AnalyticsMetricKey::EmailForward, 'label' => 'Forwards'],
            ['domain' => AnalyticsDomain::Email, 'metric' => AnalyticsMetricKey::EmailAttachments, 'label' => 'Attachments used'],

            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingRevenueMinor, 'label' => 'Revenue (minor)'],
            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingOrders, 'label' => 'Orders'],
            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingPaid, 'label' => 'Paid orders'],
            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingFailed, 'label' => 'Failed orders'],
            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingMrrMinor, 'label' => 'MRR (minor)'],
            ['domain' => AnalyticsDomain::Billing, 'metric' => AnalyticsMetricKey::BillingArrMinor, 'label' => 'ARR (minor)'],

            ['domain' => AnalyticsDomain::Affiliate, 'metric' => AnalyticsMetricKey::AffiliateClicks, 'label' => 'Affiliate clicks'],
            ['domain' => AnalyticsDomain::Affiliate, 'metric' => AnalyticsMetricKey::AffiliateSignups, 'label' => 'Affiliate signups'],
            ['domain' => AnalyticsDomain::Affiliate, 'metric' => AnalyticsMetricKey::AffiliateConversions, 'label' => 'Conversions'],
            ['domain' => AnalyticsDomain::Affiliate, 'metric' => AnalyticsMetricKey::AffiliateCommissionMinor, 'label' => 'Commission (minor)'],
            ['domain' => AnalyticsDomain::Affiliate, 'metric' => AnalyticsMetricKey::AffiliateWithdrawals, 'label' => 'Withdrawals'],

            ['domain' => AnalyticsDomain::Ads, 'metric' => AnalyticsMetricKey::AdsImpressions, 'label' => 'Impressions'],
            ['domain' => AnalyticsDomain::Ads, 'metric' => AnalyticsMetricKey::AdsClicks, 'label' => 'Clicks'],
            ['domain' => AnalyticsDomain::Ads, 'metric' => AnalyticsMetricKey::AdsCtrBps, 'label' => 'CTR (bps)'],
            ['domain' => AnalyticsDomain::Ads, 'metric' => AnalyticsMetricKey::AdsRevenueMinor, 'label' => 'Ads revenue (minor)'],

            ['domain' => AnalyticsDomain::Api, 'metric' => AnalyticsMetricKey::ApiRequests, 'label' => 'API requests'],
            ['domain' => AnalyticsDomain::Api, 'metric' => AnalyticsMetricKey::ApiErrors, 'label' => 'API errors'],
            ['domain' => AnalyticsDomain::Api, 'metric' => AnalyticsMetricKey::ApiRateLimited, 'label' => 'Rate limited'],
            ['domain' => AnalyticsDomain::Api, 'metric' => AnalyticsMetricKey::ApiKeyUsage, 'label' => 'API key usage'],
        ];
    }

    /**
     * @return list<AnalyticsMetricKey>
     */
    public function forDomain(AnalyticsDomain $domain): array
    {
        return array_values(array_map(
            static fn (array $row): AnalyticsMetricKey => $row['metric'],
            array_filter($this->all(), static fn (array $row): bool => $row['domain'] === $domain),
        ));
    }
}
