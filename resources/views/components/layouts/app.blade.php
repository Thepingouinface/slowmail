@props(['title' => null, 'nav' => true])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}SlowMail</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-stone-50 text-stone-900 antialiased">
    @if ($nav)
        <x-status-banner />

        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                <nav class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm" aria-label="Navigation principale">
                    <a href="{{ route('campaigns.index') }}" class="text-base font-semibold tracking-tight">SlowMail</a>
                    <a href="{{ route('campaigns.index') }}" @class(['font-medium', 'text-indigo-700' => request()->routeIs('campaigns.*'), 'text-stone-500 hover:text-stone-900' => ! request()->routeIs('campaigns.*')])>Campagnes</a>
                    <a href="{{ route('suppressions.index') }}" @class(['font-medium', 'text-indigo-700' => request()->routeIs('suppressions.*'), 'text-stone-500 hover:text-stone-900' => ! request()->routeIs('suppressions.*')])>Liste de suppression</a>
                </nav>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-stone-500 hover:text-stone-900">Se déconnecter</button>
                </form>
            </div>
        </header>
    @endif

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (session('status'))
            <x-alert tone="ok" class="mb-6" role="status">{{ session('status') }}</x-alert>
        @endif

        @if (session('recipient_error'))
            <x-alert tone="error" class="mb-6" role="alert">{{ session('recipient_error') }}</x-alert>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
