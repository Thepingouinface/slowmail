@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium',
    'bg-stone-200 text-stone-700' => $tone === 'neutral',
    'bg-emerald-100 text-emerald-800' => $tone === 'ok',
    'bg-amber-100 text-amber-900' => $tone === 'warn',
    'bg-red-100 text-red-800' => $tone === 'error',
    'bg-indigo-100 text-indigo-800' => $tone === 'info',
]) }}>{{ $slot }}</span>
