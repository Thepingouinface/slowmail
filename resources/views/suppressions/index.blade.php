@php
    use App\Enums\SuppressionReason;
    use App\Enums\SuppressionType;
    use App\Support\LocalTime;
@endphp
<x-layouts.app title="Liste de suppression">
    <h1 class="text-2xl font-semibold tracking-tight">Liste de suppression</h1>
    <p class="mt-2 max-w-3xl text-sm text-stone-600">
        Les adresses et les domaines qui ne recevront plus jamais de mail, dans aucune campagne. Elle est vérifiée à l'import et juste avant chaque envoi.
        Une adresse ajoutée ici sort tout de suite des envois en attente. Pour en retirer une, il faut passer par la base : ce n'est volontairement pas un bouton.
    </p>

    <form method="POST" action="{{ route('suppressions.store') }}" class="mt-6 flex flex-wrap items-start gap-3 rounded-xl border border-stone-200 bg-white p-5">
        @csrf
        <div class="min-w-64 flex-1">
            <label for="value" class="block text-sm font-medium">Adresse ou domaine</label>
            <input id="value" name="value" type="text" required maxlength="255" value="{{ old('value') }}" placeholder="prenom.nom@societe.example ou @societe.example"
                class="mt-1 block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            <p class="mt-1 text-xs text-stone-500">Écrivez <code class="font-mono">@societe.example</code> pour un domaine entier.</p>
            @error('value')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="reason" class="block text-sm font-medium">Motif</label>
            <select id="reason" name="reason" class="mt-1 block rounded-lg border border-stone-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @foreach (SuppressionReason::cases() as $reasonOption)
                    <option value="{{ $reasonOption->value }}" @selected(old('reason', 'manuel') === $reasonOption->value)>{{ $reasonOption->label() }}</option>
                @endforeach
            </select>
            @error('reason')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <div class="self-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-700">Ajouter</button>
        </div>
    </form>

    <form method="GET" action="{{ route('suppressions.index') }}" class="mt-8 flex flex-wrap items-center gap-2">
        <h2 class="mr-2 font-semibold">Liste <span class="font-normal text-stone-500">({{ $total }})</span></h2>
        <label for="q" class="sr-only">Chercher</label>
        <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Chercher"
            class="w-48 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
        <label for="filter-reason" class="sr-only">Motif</label>
        <select id="filter-reason" name="reason" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm">
            <option value="">Tous les motifs</option>
            @foreach (SuppressionReason::cases() as $reasonOption)
                <option value="{{ $reasonOption->value }}" @selected($reason === $reasonOption)>{{ $reasonOption->label() }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Filtrer</button>
    </form>

    <div class="mt-4 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        @if ($suppressions->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-stone-500">{{ $total === 0 ? 'La liste est vide.' : 'Rien ne correspond.' }}</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Adresse ou domaine</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Motif</th>
                        <th class="px-4 py-3 font-medium">Ajouté le</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($suppressions as $suppression)
                        <tr data-suppression="{{ $suppression->id }}">
                            <td class="px-4 py-2.5 font-mono text-[13px]">{{ $suppression->type === SuppressionType::Domain ? '@' : '' }}{{ $suppression->value }}</td>
                            <td class="px-4 py-2.5 text-stone-600">{{ $suppression->type->label() }}</td>
                            <td class="px-4 py-2.5"><x-pill :tone="$suppression->reason === SuppressionReason::Bounce ? 'error' : 'warn'">{{ $suppression->reason->label() }}</x-pill></td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-stone-600">{{ LocalTime::compact($suppression->created_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">{{ $suppressions->links() }}</div>
</x-layouts.app>
