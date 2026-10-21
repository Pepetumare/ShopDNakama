@props(['action'])
<form method="POST" action="{{ $action }}" {{ $attributes->class(['auth-form']) }}>
    @csrf
    {{ $slot }}
</form>
