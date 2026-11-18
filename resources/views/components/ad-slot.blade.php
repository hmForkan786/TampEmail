@props([
    'placement',
    'track' => true,
])

@php
    use App\Services\Ads\AdDecisionEngine;

    $decision = app(AdDecisionEngine::class)->decide(
        placementKey: (string) $placement,
        user: auth()->user(),
        device: null,
        language: app()->getLocale(),
        theme: null,
        recordImpression: (bool) $track,
    );
@endphp

@if ($decision->show && $decision->render !== null)
    <div {{ $attributes->class(['ad-slot']) }} data-ad-placement="{{ $decision->placementKey }}" data-ad-campaign="{{ $decision->campaignId }}" data-ad-impression="{{ $decision->impressionId }}">
        @switch($decision->render->type)
            @case('google_adsense')
                {{-- Structured AdSense config only; script tags loaded by layout when needed --}}
                <div
                    class="adsense-slot"
                    data-ad-client="{{ $decision->render->data['publisher_id'] ?? '' }}"
                    data-ad-slot="{{ $decision->render->data['slot_id'] ?? '' }}"
                    data-ad-responsive="{{ ($decision->render->data['responsive'] ?? true) ? 'true' : 'false' }}"
                ></div>
                @break

            @case('direct_banner')
                <a
                    href="{{ $decision->render->data['click_url'] ?? '#' }}"
                    rel="noopener sponsored nofollow"
                    target="_blank"
                    data-ad-track-click="1"
                >
                    <img
                        src="{{ $decision->render->data['image_url'] ?? '' }}"
                        alt="{{ $decision->render->data['alt'] ?? 'Advertisement' }}"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                    >
                </a>
                @break

            @case('house_ads')
                <aside class="house-ad" data-promotion-kind="{{ $decision->render->data['promotion_kind'] ?? 'generic' }}">
                    <strong>{{ $decision->render->data['headline'] ?? '' }}</strong>
                    @if (!empty($decision->render->data['body']))
                        <div>{!! $decision->render->data['body'] !!}</div>
                    @endif
                    <a href="{{ $decision->render->data['cta_url'] ?? '#' }}" rel="noopener">
                        {{ $decision->render->data['cta_label'] ?? 'Learn more' }}
                    </a>
                </aside>
                @break

            @case('custom_html')
                <div class="custom-ad">{!! $decision->render->data['markup'] ?? '' !!}</div>
                @break
        @endswitch
    </div>
@endif
