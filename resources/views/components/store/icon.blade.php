@props(['name', 'size' => 24])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
@switch($name)
@case('torii')<path d="M2 3c6 2 14 2 20 0M3 6h18M5 6l-1 16M19 6l1 16M4 12h16M9 6v6M15 6v6"/>@break
@case('fire')<path d="M13 2c-5 5 0 7-4 10-2-1-2-4-2-4-5 6-5 14 5 14 9 0 11-10 5-15 0 4-3 5-4 3 2-3 2-6 0-8Z" fill="#f02128" stroke="#f02128"/><path d="M12 13c-4 4-2 8 1 8s5-4-1-8" fill="#f4b400" stroke="#f4b400"/>@break
@case('gear')<path d="m9 2-1 4-3-1-3 5 3 3-1 4 5 3 3-3 4 1 3-5-3-3 1-4-5-3Z" fill="currentColor"/><circle cx="11" cy="11" r="4" fill="white"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
@case('user')<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3Z"/>@break
@case('heart')<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>@break
@case('cart')<path d="M2 3h3l3 13h11l3-10H6"/><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>@break
@case('truck')<path d="M1 5h13v12H1ZM14 9h5l4 5v3h-9"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/>@break
@case('shield')<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6Z"/><path d="m8 11 3 3 5-6"/>@break
@case('cube')<path d="m12 2 10 5v10l-10 5-10-5V7Zm0 10v10M2 7l10 5 10-5M12 2v10"/>@break
@case('shirt')<path d="m8 3-6 4 3 5 3-2v12h8V10l3 2 3-5-6-4c-2 3-6 3-8 0Z"/>@break
@case('mail')<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/>@break
@case('pin')<path d="M19 9c0 6-7 13-7 13S5 15 5 9a7 7 0 1 1 14 0Z"/><circle cx="12" cy="9" r="2"/>@break
@case('star')<path d="m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z"/>@break
@case('menu')<path d="M3 5h18M3 12h18M3 19h18"/>@break
@default <path d="M5 12h14m-5-5 5 5-5 5"/>
@endswitch
</svg>
