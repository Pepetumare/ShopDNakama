@props(['type'])
@if(str_starts_with($type, 'shirt'))
<x-store.icon name="shirt" size="80"/>
@elseif($type === 'patch')
<x-store.icon name="torii" size="80"/>
@elseif($type === '3d_print')
<svg width="90" height="90" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round" aria-hidden="true"><path d="M15 88V12h70v76M8 88h84M24 74h52M24 22h52M50 22v12m-7 0h14v10H43ZM50 44v7M34 61l16-8 16 8v16l-16 8-16-8V61Zm0 0 16 8 16-8M50 69v16"/></svg>
@else
<svg width="90" height="90" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round" aria-hidden="true">
    @if($type === 'mug')
    <path d="M20 25h48v48c0 12-48 12-48 0V25Zm48 6h9c22 0 22 30 0 30h-9M32 14v-8m13 8v-8m13 8v-8"/><path d="m44 38 4 9 10 1-7 7 2 10-9-5-9 5 2-10-7-7 10-1Z" fill="currentColor"/>
    @elseif($type === 'badges')
    <circle cx="50" cy="50" r="35"/><circle cx="50" cy="50" r="28" stroke-dasharray="3 4"/><path d="m50 29 6 14 15 2-11 10 3 15-13-7-13 7 3-15-11-10 15-2Z" fill="currentColor"/>
    @else
    <path d="M20 16h60v47L59 84H20V16Z"/><path d="M80 63H59v21M34 32l8 8m16-8-8 8m-17 13c8 10 19 10 27 0"/>
    @endif
</svg>
@endif
