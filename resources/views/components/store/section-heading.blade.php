@props(['icon' => 'star', 'subtitle' => null])
<div {{ $attributes->class(['section-heading']) }}><x-store.icon :name="$icon" size="29"/><h2>{{ $slot }}</h2>@if($subtitle)<p>{{ $subtitle }}</p>@endif</div>
