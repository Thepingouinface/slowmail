<x-layouts.app title="Rapport d'import">
    <a href="{{ route('campaigns.show', $campaign) }}" class="text-sm text-stone-500 hover:text-stone-900">← {{ $campaign->name }}</a>

    <h1 class="mt-2 text-2xl font-semibold tracking-tight">Rapport d'import</h1>
    <p class="mt-1 text-sm text-stone-500">
        Séparateur détecté : <code class="font-mono">{{ $analysis->delimiter }}</code> ·
        {{ $analysis->totalRows }} ligne(s) lue(s) ·
        Variables : {{ $analysis->columns === [] ? 'aucune' : implode(', ', $analysis->columns) }}
    </p>

    @foreach ($analysis->warnings as $warning)
        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $warning }}</div>
    @endforeach

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="text-3xl font-semibold tabular-nums text-emerald-700">{{ count($analysis->importable) }}</div>
            <div class="mt-1 text-sm text-stone-500">à importer</div>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="text-3xl font-semibold tabular-nums text-amber-700">{{ count($analysis->contacted) }}</div>
            <div class="mt-1 text-sm text-stone-500">déjà contactées dans une autre campagne</div>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="text-3xl font-semibold tabular-nums text-red-700">{{ count($analysis->rejected) }}</div>
            <div class="mt-1 text-sm text-stone-500">rejetées</div>
        </div>
    </div>

    @if ($analysis->rejected !== [])
        <section class="mt-8">
            <h2 class="font-semibold">Lignes rejetées</h2>
            <div class="mt-3 overflow-hidden rounded-xl border border-stone-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Ligne</th>
                            <th class="px-4 py-3 font-medium">Adresse</th>
                            <th class="px-4 py-3 font-medium">Raison</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($analysis->rejected as $row)
                            <tr>
                                <td class="px-4 py-2.5 tabular-nums text-stone-500">{{ $row['line'] }}</td>
                                <td class="px-4 py-2.5 font-mono text-[13px]">{{ $row['email'] ?: '(vide)' }}</td>
                                <td class="px-4 py-2.5 text-stone-700">{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($analysis->contacted !== [])
        <section class="mt-8">
            <h2 class="font-semibold">Déjà contactées dans une autre campagne</h2>
            <div class="mt-3 overflow-hidden rounded-xl border border-stone-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Ligne</th>
                            <th class="px-4 py-3 font-medium">Adresse</th>
                            <th class="px-4 py-3 font-medium">Campagne</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($analysis->contacted as $row)
                            <tr>
                                <td class="px-4 py-2.5 tabular-nums text-stone-500">{{ $row['line'] }}</td>
                                <td class="px-4 py-2.5 font-mono text-[13px]">{{ $row['email'] }}</td>
                                <td class="px-4 py-2.5 text-stone-700">{{ $row['campaign'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mt-8 flex flex-wrap items-center gap-4">
        @if ($token)
            <form method="POST" action="{{ route('campaigns.import.confirm', [$campaign, $token]) }}" class="flex flex-wrap items-center gap-4">
                @csrf
                <input type="hidden" name="email_column" value="{{ $emailColumn }}">

                @if ($analysis->contacted !== [])
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="include_contacted" value="1" class="rounded border-stone-300">
                        Importer aussi les {{ count($analysis->contacted) }} adresse(s) déjà contactée(s)
                    </label>
                @endif

                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Lancer l'import
                </button>
            </form>

            <form method="POST" action="{{ route('campaigns.import.cancel', [$campaign, $token]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-stone-500 hover:text-stone-900">Annuler</button>
            </form>
        @else
            <p class="text-sm text-stone-600">Rien à importer : le fichier a été supprimé.</p>
            <a href="{{ route('campaigns.show', $campaign) }}" class="text-sm font-medium text-indigo-700 hover:underline">Retour à la campagne</a>
        @endif
    </div>
</x-layouts.app>
