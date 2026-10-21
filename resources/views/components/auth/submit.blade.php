<button {{ $attributes->class(['auth-submit'])->merge(['type' => 'submit']) }}>{{ $slot }} <span aria-hidden="true">→</span></button>
