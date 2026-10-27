@props(['tone' => 'info'])

<div {{ $attributes->class([
    'rounded-lg border px-4 py-3 text-sm',
    'border-emerald-300 bg-emerald-50 text-emerald-900' => $tone === 'ok',
    'border-amber-300 bg-amber-50 text-amber-900' => $tone === 'warn',
    'border-red-300 bg-red-50 text-red-900' => $tone === 'error',
    'border-stone-300 bg-stone-50 text-stone-700' => $tone === 'info',
]) }}>{{ $slot }}</div>
