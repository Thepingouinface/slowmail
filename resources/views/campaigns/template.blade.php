<x-layouts.app :title="'Modèle · '.$campaign->name">
    <x-campaign-header :campaign="$campaign" active="template" />

    <div class="mt-8 grid gap-8 lg:grid-cols-2">
        <div class="space-y-6">
            <form method="POST" action="{{ route('campaigns.template.update', $campaign) }}" class="space-y-4 rounded-xl border border-stone-200 bg-white p-5">
                @csrf
                @method('PUT')
                <input type="hidden" name="recipient" value="{{ $selected?->id }}">

                <div>
                    <label for="subject" class="block text-sm font-medium">Objet</label>
                    <input id="subject" name="subject" type="text" maxlength="300" value="{{ old('subject', $campaign->subject) }}"
                        class="mt-1 block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    @error('subject')
                        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="body" class="block text-sm font-medium">Corps <span class="font-normal text-stone-500">(texte brut)</span></label>
                    <textarea id="body" name="body" rows="16" maxlength="20000"
                        class="mt-1 block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 font-mono text-[13px] leading-relaxed shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('body', $campaign->body) }}</textarea>
                    @error('body')
                        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Enregistrer</button>
                    <span class="text-xs text-stone-500">L'aperçu et le test utilisent le modèle enregistré.</span>
                </div>
            </form>

            <section class="rounded-xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Variables disponibles</h2>
                <p class="mt-1 text-sm text-stone-500">Les colonnes de vos CSV, plus <code class="font-mono">[DATE]</code> (jour de l'envoi) et <code class="font-mono">[EMAIL]</code>.</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ([...$columns, ...App\Services\Template\Variables::BUILT_IN] as $name)
                        <code class="rounded-md bg-stone-100 px-2 py-0.5 font-mono text-xs text-stone-700">[{{ $name }}]</code>
                    @endforeach
                </div>
                @if ($columns === [])
                    <p class="mt-3 text-sm text-stone-500">Aucune colonne pour l'instant : importez un CSV dans l'onglet Destinataires.</p>
                @endif

                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer font-medium text-indigo-700">Syntaxe</summary>
                    <dl class="mt-3 space-y-2">
                        <div><dt class="font-mono text-xs">[STRUCTURE]</dt><dd class="text-stone-600">Remplacé par la colonne <code class="font-mono">STRUCTURE</code> du CSV. Casse et espaces autour sans importance.</dd></div>
                        <div><dt class="font-mono text-xs">[STRUCTURE|votre structure]</dt><dd class="text-stone-600">Valeur par défaut si la colonne est vide. <code class="font-mono">[TITRE|]</code> : défaut vide, la ligne part quand même.</dd></div>
                        <div><dt class="font-mono text-xs">\[texte\]</dt><dd class="text-stone-600">Crochets littéraux, non remplacés.</dd></div>
                        <div><dt class="text-stone-600">Une variable vide sans valeur par défaut empêche l'envoi à cette ligne : elle est ignorée.</dt></div>
                    </dl>
                </details>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Contrôles</h2>

                @if (blank($campaign->subject) && blank($campaign->body))
                    <p class="mt-2 text-sm text-stone-500">Écrivez l'objet et le corps, puis enregistrez : les contrôles s'affichent ici.</p>
                @elseif ($validation->errors === [] && $validation->blockers === [] && $validation->warnings === [])
                    <p class="mt-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Le modèle est valide : il pourra servir à activer la campagne.</p>
                @else
                    @if ($validation->errors !== [])
                        <ul class="mt-3 space-y-2">
                            @foreach ($validation->errors as $error)
                                <li class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($validation->blockers !== [])
                        <ul class="mt-3 space-y-2">
                            @foreach ($validation->blockers as $blocker)
                                <li class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($validation->warnings !== [])
                        <ul class="mt-3 space-y-2">
                            @foreach ($validation->warnings as $warning)
                                <li class="rounded-lg border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-stone-700">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Aperçu</h2>

                <form method="GET" action="{{ route('campaigns.template', $campaign) }}" class="mt-3 flex flex-wrap items-center gap-2">
                    <label for="q" class="sr-only">Filtrer par adresse</label>
                    <input id="q" name="q" type="search" value="{{ $filter }}" placeholder="Filtrer par adresse"
                        class="w-40 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    <label for="recipient" class="sr-only">Ligne du CSV</label>
                    <select id="recipient" name="recipient"
                        class="min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        @forelse ($choices as $choice)
                            <option value="{{ $choice->id }}" @selected($selected?->id === $choice->id)>#{{ $choice->position }} · {{ $choice->email }}</option>
                        @empty
                            <option value="">Aucune ligne</option>
                        @endforelse
                    </select>
                    <button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Voir</button>
                </form>

                @if ($selected === null)
                    <p class="mt-4 text-sm text-stone-500">Aucun destinataire : importez un CSV pour voir un aperçu.</p>
                @elseif (blank($campaign->subject) && blank($campaign->body))
                    <p class="mt-4 text-sm text-stone-500">Écrivez et enregistrez le modèle pour voir l'aperçu.</p>
                @elseif ($preview === null)
                    <p class="mt-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">Corrigez les erreurs du modèle pour voir l'aperçu.</p>
                @else
                    @if ($preview->missing !== [])
                        <p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            Cette ligne serait ignorée : valeur vide pour
                            {{ implode(', ', array_map(fn ($name) => '['.$name.']', $preview->missing)) }},
                            sans valeur par défaut.
                        </p>
                    @endif

                    <div class="mt-4 rounded-lg border border-stone-200 bg-stone-50 p-4">
                        <div class="text-xs uppercase tracking-wide text-stone-500">Objet</div>
                        <div class="mt-1 font-medium">{{ $preview->subject }}</div>
                        <div class="mt-4 text-xs uppercase tracking-wide text-stone-500">Corps</div>
                        <pre class="mt-1 whitespace-pre-wrap font-sans text-sm leading-relaxed">{{ $preview->body }}</pre>
                    </div>
                @endif

                @if ($selected !== null)
                    <form method="POST" action="{{ route('campaigns.template.test', $campaign) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="recipient_id" value="{{ $selected->id }}">
                        <button type="submit" @disabled($testProblem !== null)
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-stone-300">
                            M'envoyer un test
                        </button>
                        <p class="mt-2 text-xs text-stone-500">
                            Vrai envoi à {{ $fromAddress !== '' ? $fromAddress : '(MAIL_FROM_ADDRESS non défini)' }}, avec les données de la ligne choisie.
                            Le statut de la ligne ne change pas.
                        </p>
                        @if ($testProblem !== null)
                            <p class="mt-2 text-sm text-amber-800">{{ $testProblem }}</p>
                        @endif
                    </form>
                @endif

                @error('test')
                    <p class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $message }}</p>
                @enderror
            </section>
        </div>
    </div>
</x-layouts.app>
