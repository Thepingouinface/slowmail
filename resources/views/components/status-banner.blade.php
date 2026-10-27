{{-- Bandeau d'état global (addendum §6). Lu au chargement de la page : l'heure exacte du dernier passage sert à juger de son âge. --}}
@unless ($snapshot->enabled)
    <div class="border-b border-amber-300 bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">
        Envois désactivés (<code class="font-mono">SLOWMAIL_ENABLED=false</code>) : aucun mail ne partira.
    </div>
@endunless

<div class="border-b border-stone-200 bg-stone-100 text-sm" role="status" aria-label="État de SlowMail">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2">
        @if ($ago === null)
            <span class="font-medium text-red-700" data-banner="scheduler">
                <span aria-hidden="true">●</span> Scheduler : jamais exécuté. Le service <code class="font-mono">scheduler</code> tourne-t-il ?
            </span>
        @elseif ($snapshot->stale)
            <span class="font-medium text-red-700" data-banner="scheduler">
                <span aria-hidden="true">●</span> Dernier passage du scheduler : {{ $ago }} ({{ $lastTick }}) : en retard, le Mac dormait-il ou OrbStack est-il arrêté ?
            </span>
        @else
            <span class="text-stone-700" data-banner="scheduler">
                <span class="text-emerald-600" aria-hidden="true">●</span> Dernier passage du scheduler : {{ $ago }} ({{ $lastTick }})
            </span>
        @endif

        @if ($snapshot->uncertainCount > 0)
            <a href="{{ $uncertainUrl }}" class="font-medium text-amber-800 underline" data-banner="uncertain">
                {{ $snapshot->uncertainCount }} envoi(s) à vérifier dans vos envoyés
            </a>
        @endif

        @if ($snapshot->pausedCampaigns > 0)
            <a href="{{ route('campaigns.index') }}" class="font-medium text-amber-800 underline" data-banner="paused">
                {{ $snapshot->pausedCampaigns }} campagne(s) en pause
            </a>
        @endif

        <span class="text-stone-700" data-banner="next">
            Prochain envoi prévu : {{ $nextSend ?? 'aucun' }}
        </span>
    </div>
</div>
