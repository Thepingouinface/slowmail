@props(['campaign', 'active'])

@php
    use App\Enums\CampaignStatus;
    use App\Enums\RecipientStatus;
    use App\Services\Planning\CampaignSettings;
    use App\Support\SecretScrubber;

    $settings = CampaignSettings::for($campaign);
    $uncertain = $campaign->recipients()->where('status', RecipientStatus::Uncertain)->count();
    $threshold = (int) config('slowmail.sending.breaker_threshold');
    $breaker = $campaign->status === CampaignStatus::Paused && $campaign->consecutive_failures >= $threshold;
    // La confirmation dit clairement si de vrais mails vont partir.
    $confirm = fn (string $verb) => $settings->dryRun
        ? "{$verb} la campagne en simulation ? Aucun mail ne partira : les envois seront seulement simulés."
        : "{$verb} la campagne ? De vrais mails partiront à partir de maintenant, au rythme : ".$settings->summary();
@endphp

<a href="{{ route('campaigns.index') }}" class="text-sm text-stone-500 hover:text-stone-900">← Campagnes</a>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $campaign->name }}</h1>
        <x-pill :tone="$campaign->status->tone()" data-campaign-status>{{ $campaign->status->label() }}</x-pill>
        @if ($settings->dryRun)
            <x-pill tone="info" title="Aucun mail ne part : les envois sont simulés (statut Simulé, rendu gardé).">Simulation</x-pill>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('campaigns.export', $campaign) }}" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Exporter en CSV</a>

        @if ($campaign->status === CampaignStatus::Draft)
            <form method="POST" action="{{ route('campaigns.activate', $campaign) }}" data-confirm="{{ $confirm('Activer') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Activer</button>
            </form>
        @elseif ($campaign->status === CampaignStatus::Active)
            <form method="POST" action="{{ route('campaigns.pause', $campaign) }}">
                @csrf
                <button type="submit" class="rounded-lg border border-amber-400 bg-amber-50 px-4 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100">Mettre en pause</button>
            </form>
        @elseif ($campaign->status === CampaignStatus::Paused)
            <form method="POST" action="{{ route('campaigns.resume', $campaign) }}" data-confirm="{{ $confirm('Reprendre') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Reprendre</button>
            </form>
        @endif
    </div>
</div>

<p class="mt-2 text-sm text-stone-600">{{ $settings->summary() }}</p>

<div class="mt-4 space-y-3">
    @if (session('lifecycle_problems'))
        <x-alert tone="error" role="alert" data-lifecycle-problems>
            <p class="font-medium">La campagne ne peut pas démarrer :</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach (session('lifecycle_problems') as $problem)
                    <li>{{ SecretScrubber::scrub($problem) }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($campaign->status === CampaignStatus::Paused && $campaign->pause_reason !== null)
        <x-alert :tone="$breaker ? 'error' : 'warn'" data-pause-reason>
            <p class="font-medium">{{ $breaker ? 'Disjoncteur : la campagne s\'est mise en pause toute seule.' : 'En pause.' }}</p>
            <p class="mt-1">{{ SecretScrubber::scrub($campaign->pause_reason) }}</p>
            @if ($campaign->consecutive_failures > 0)
                <p class="mt-1">Échecs d'envoi consécutifs : {{ $campaign->consecutive_failures }} (la pause se déclenche à {{ $threshold }}). Corrigez la cause (voir le journal), puis « Reprendre ».</p>
            @endif
        </x-alert>
    @elseif ($campaign->consecutive_failures > 0 && $campaign->status === CampaignStatus::Active)
        <x-alert tone="warn" data-failures>
            Échecs d'envoi consécutifs : {{ $campaign->consecutive_failures }} sur {{ $threshold }} avant la pause automatique.
            <a href="{{ route('campaigns.journal', $campaign) }}" class="underline">Voir le journal</a>.
        </x-alert>
    @endif

    @if ($uncertain > 0 && $active !== 'recipients')
        <x-alert tone="warn" data-uncertain>
            {{ $uncertain }} envoi(s) à vérifier dans vos envoyés.
            <a href="{{ route('campaigns.show', [$campaign, 'status' => 'uncertain']) }}" class="font-medium underline">Les trancher</a>.
        </x-alert>
    @endif
</div>

<nav class="mt-6 flex gap-6 border-b border-stone-200 text-sm" aria-label="Sections de la campagne">
    @foreach ([
        'recipients' => ['Destinataires', route('campaigns.show', $campaign)],
        'template' => ['Modèle', route('campaigns.template', $campaign)],
        'settings' => ['Réglages', route('campaigns.settings', $campaign)],
        'journal' => ['Journal', route('campaigns.journal', $campaign)],
    ] as $key => [$label, $url])
        <a href="{{ $url }}"
            @class([
                '-mb-px border-b-2 pb-3 font-medium',
                'border-indigo-600 text-indigo-700' => $active === $key,
                'border-transparent text-stone-500 hover:text-stone-900' => $active !== $key,
            ])
            @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
