<x-layouts.app title="Campagnes">
    <div class="flex items-end justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Campagnes</h1>
    </div>

    <form method="POST" action="{{ route('campaigns.store') }}" class="mt-6 flex flex-wrap items-start gap-3">
        @csrf
        <div class="min-w-64 flex-1">
            <label for="name" class="sr-only">Nom de la nouvelle campagne</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="Nom de la nouvelle campagne" required maxlength="120"
                class="block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            @error('name')
                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-700">
            Créer
        </button>
    </form>

    <div class="mt-8 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        @forelse ($campaigns as $campaign)
            @if ($loop->first)
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Nom</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 font-medium">Avancement</th>
                            <th class="px-4 py-3 font-medium">Prochain envoi prévu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
            @endif
                        @php
                            $simulation = App\Services\Planning\CampaignSettings::for($campaign)->dryRun;
                            $total = max(1, $campaign->recipients_count);
                            $percent = (int) round($campaign->sent_count / $total * 100);
                            $simulatedPercent = (int) round($campaign->simulated_count / $total * 100);
                        @endphp
                        <tr class="align-top hover:bg-stone-50" data-campaign="{{ $campaign->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-indigo-700 hover:underline">{{ $campaign->name }}</a>
                                @if ($simulation)
                                    <x-pill tone="info" class="ml-2" title="Aucun mail ne part : les envois sont simulés.">Simulation</x-pill>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-pill :tone="$campaign->status->tone()">{{ $campaign->status->label() }}</x-pill>
                                @if ($campaign->status === App\Enums\CampaignStatus::Paused && $campaign->pause_reason)
                                    <p class="mt-1 max-w-xs text-xs text-stone-500">{{ Str::limit(App\Support\SecretScrubber::scrub($campaign->pause_reason), 90) }}</p>
                                @endif
                                @if ($campaign->uncertain_count > 0)
                                    <p class="mt-1 text-xs">
                                        <a href="{{ route('campaigns.show', [$campaign, 'status' => 'uncertain']) }}" class="font-medium text-amber-800 underline">{{ $campaign->uncertain_count }} à vérifier</a>
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="tabular-nums">{{ $campaign->sent_count }} / {{ $campaign->recipients_count }}</span>
                                @if ($campaign->simulated_count > 0)
                                    <span class="text-xs text-indigo-700" data-simulated>+ {{ $campaign->simulated_count }} simulé(s)</span>
                                @endif
                                <div class="mt-1 flex h-1.5 w-32 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Envoyés sur le total">
                                    <div class="h-full bg-emerald-500" style="width: {{ $percent }}%"></div>
                                    <div class="h-full bg-indigo-300" style="width: {{ $simulatedPercent }}%"></div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-stone-600">
                                @if ($campaign->status === App\Enums\CampaignStatus::Active)
                                    {{ $nextSends[$campaign->id] ?? '—' }}
                                @elseif ($campaign->status === App\Enums\CampaignStatus::Paused)
                                    en pause
                                @elseif ($campaign->status === App\Enums\CampaignStatus::Draft)
                                    pas encore activée
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
            @if ($loop->last)
                    </tbody>
                </table>
            @endif
        @empty
            <p class="px-4 py-10 text-center text-sm text-stone-500">Aucune campagne. Créez-en une ci-dessus, puis importez un CSV.</p>
        @endforelse
    </div>
</x-layouts.app>
