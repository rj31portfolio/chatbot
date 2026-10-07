<a href="#main-content" class="marketing-skip-link">Skip to content</a>
<header class="marketing-header">
    <nav class="growth-nav" aria-label="Main navigation">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark">@if($logoUrl)<img src="{{ $logoUrl }}" alt="" width="27" height="27">@else<x-icon name="spark"/>@endif</span><span>{{ $brand }}<small>YOUR WEBSITE. YOUR NEXT CUSTOMER.</small></span></a>
        <div class="growth-nav-links" id="marketing-navigation"><a href="{{ route('home') }}#features">Features</a><a href="{{ route('home') }}#how-it-works">How it works</a><a href="{{ route('home') }}#demo">Watch demo</a><a href="{{ route('home') }}#pricing">Pricing</a><a href="{{ route('widget.installation-guide') }}" @if(request()->routeIs('widget.installation-guide')) aria-current="page" @endif>Install widget</a></div>
        <div class="growth-nav-actions">@auth<a href="{{ route('dashboard') }}" class="button primary">Dashboard <x-icon name="arrow"/></a>@else<a href="{{ route('login') }}">Sign in</a><a href="{{ route('register') }}" class="button primary">Start free <x-icon name="arrow"/></a>@endauth<button type="button" class="marketing-menu-button" data-marketing-menu aria-label="Open menu" aria-controls="marketing-navigation" aria-expanded="false"><x-icon name="menu"/></button></div>
    </nav>
</header>
