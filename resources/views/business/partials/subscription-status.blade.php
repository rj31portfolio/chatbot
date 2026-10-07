@if($subscription)
@php
    $expiry = $subscription->ends_at ?? ($subscription->status === 'trial' ? $subscription->trial_ends_at : null);
    $isExpired = $expiry?->isPast() ?? false;
    $isPaused = !in_array($subscription->status, ['active', 'trial'], true);
@endphp
<section @class(['subscription-banner', 'subscription-warning' => $isExpired || $isPaused]) aria-label="Subscription status">
    <div><span class="eyebrow">YOUR SUBSCRIPTION</span><h2>{{ $subscription->plan->name }} <span class="badge {{ $isExpired || $isPaused ? 'neutral' : 'green' }}">{{ $isExpired ? 'Expired' : ucfirst($subscription->status) }}</span></h2><p>{{ $expiry ? 'Access ends '.$expiry->format('d M Y, H:i T') : 'Free plan · No expiry date' }}</p></div>
    <div class="subscription-clock">
        @if($isPaused)<strong>Access paused</strong>
        @elseif($expiry)<strong data-expiry="{{ $expiry->toIso8601String() }}">{{ $isExpired ? 'Expired' : (int) now()->diffInDays($expiry).' days remaining' }}</strong>
        @else<strong>Ready when you are</strong>@endif
        <small>{{ $expiry ? 'Renew to keep your agent working' : 'Upgrade as your business grows' }}</small>
    </div>
    <a href="{{ route('subscription') }}#plans" class="button secondary">{{ $expiry ? 'Renew subscription' : 'Explore plans' }} <x-icon name="arrow"/></a>
</section>
@endif
