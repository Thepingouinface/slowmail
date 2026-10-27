@php
    use App\Enums\RecipientStatus;
    use App\Enums\ReplyStatus;
    use App\Support\LocalTime;
    use App\Support\SecretScrubber;
@endphp
<x-layouts.app :title="$campaign->name">
    <x-campaign-header :campaign="$campaign" active="recipients" />

    @if ($result = session('import_result'))
        <div class="mt-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <p><strong>{{ $result['imported'] }}</strong> ligne(s) importée(s).</p>

            @if ($result['contacted'] > 0)
                <p class="mt-1">
                    {{ $result['contacted'] }} adresse(s) déjà contactée(s) dans une autre campagne :
                    {{ $result['contacted_included'] ? 'importées.' : 'ignorées.' }}
                </p>
            @endif

            @foreach ($result['warnings'] as $warning)
                <p class="mt-1 text-amber-800">{{ $warning }}</p>
            @endforeach

            @if ($result['rejected_total'] > 0)
                <details class="mt-2">
                    <summary class="cursor-pointer font-medium">{{ $result['rejected_total'] }} ligne(s) rejetée(s)</summary>
                    <ul class="mt-2 space-y-0.5">
                        @foreach ($result['rejected'] as $row)
                            <li>Ligne {{ $row['line'] }} · <span class="font-mono">{{ $row['email'] ?: '(vide)' }}</span> · {{ $row['reason'] }}</li>
                        @endforeach
                        @if ($result['rejected_total'] > count($result['rejected']))
                            <li>… et {{ $result['rejected_total'] - count($result['rejected']) }} autre(s).</li>
                        @endif
                    </ul>
                </details>
            @endif
        </div>
    @endif

    @if ($uncertain->isNotEmpty())
        <section class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-5" aria-labelledby="uncertain-title" data-uncertain-panel>
            <h2 id="uncertain-title" class="font-semibold text-amber-950">À vérifier dans vos envoyés ({{ $uncertainTotal }})</h2>
            <p class="mt-1 text-sm text-amber-900">
                L'envoi de ces mails a été interrompu : SlowMail ne sait pas s'ils sont partis, et ne les renverra jamais tout seul.
                Cherchez chacun dans votre boîte d'envoi (destinataire, objet ou identifiant du message), puis tranchez.
            </p>

            <ul class="mt-4 space-y-3">
                @foreach ($uncertain as $row)
                    <li class="rounded-lg border border-amber-200 bg-white p-4 text-sm" data-uncertain-row="{{ $row->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[13px] font-medium">{{ $row->email }}</p>
                                <p class="mt-1 text-stone-600">
                                    Envoi commencé le {{ LocalTime::dateTime($row->sending_started_at, $timezone) }}
                                    @if ($row->rendered_subject !== null)
                                        · objet : « {{ $row->rendered_subject }} »
                                    @endif
                                </p>
                                @if ($row->message_id)
                                    <p class="mt-1 break-all text-xs text-stone-500">Identifiant du message : <span class="font-mono">{{ $row->message_id }}</span></p>
                                @endif
                                @if ($row->last_error)
                                    <p class="mt-1 text-xs text-stone-500">{{ SecretScrubber::scrub($row->last_error) }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <form method="POST" action="{{ route('recipients.confirm-sent', [$campaign, $row]) }}"
                                    data-confirm="Le mail à {{ $row->email }} est bien dans vos envoyés ? Il sera noté comme envoyé.">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">C'est parti</button>
                                </form>
                                <form method="POST" action="{{ route('recipients.not-sent', [$campaign, $row]) }}"
                                    data-confirm="Vous avez vérifié que le mail à {{ $row->email }} n'est PAS dans vos envoyés ? Il retournera en attente et partira à un prochain créneau.">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Pas parti</button>
                                </form>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($uncertainTotal > $uncertain->count())
                <p class="mt-3 text-sm text-amber-900">
                    … et {{ $uncertainTotal - $uncertain->count() }} autre(s) :
                    <a href="{{ route('campaigns.show', [$campaign, 'status' => 'uncertain']) }}" class="font-medium underline">voir toutes les lignes à vérifier</a>.
                </p>
            @endif
        </section>
    @endif

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-5">
        <h2 class="font-semibold">Importer un CSV</h2>
        <p class="mt-1 text-sm text-stone-500">
            Séparateur <code class="font-mono">;</code> ou <code class="font-mono">,</code> détecté, UTF-8. Le fichier est analysé d'abord ; rien n'est ajouté avant votre confirmation.
        </p>

        <form method="POST" action="{{ route('campaigns.import.analyze', $campaign) }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-4">
            @csrf
            <div>
                <label for="file" class="block text-sm font-medium">Fichier</label>
                <input id="file" name="file" type="file" accept=".csv,.txt,text/csv" required class="mt-1 block text-sm">
            </div>
            <div>
                <label for="email_column" class="block text-sm font-medium">Colonne des adresses</label>
                <input id="email_column" name="email_column" type="text" value="{{ old('email_column', 'EMAIL') }}" maxlength="100"
                    class="mt-1 block w-40 rounded-lg border border-stone-300 bg-white px-3 py-1.5 font-mono text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Analyser</button>
        </form>

        @error('file')
            <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </section>

    <section class="mt-8">
        <h2 class="font-semibold">Destinataires <span class="font-normal text-stone-500">({{ $total }})</span></h2>

        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <a href="{{ route('campaigns.show', array_filter([$campaign, 'q' => $search])) }}"
                class="rounded-full border px-3 py-1 {{ $filter === null ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-100' }}">
                Tous · {{ $total }}
            </a>
            @foreach (RecipientStatus::cases() as $status)
                @if (($counts[$status->value] ?? 0) > 0)
                    <a href="{{ route('campaigns.show', array_filter([$campaign, 'status' => $status->value, 'q' => $search])) }}"
                        class="rounded-full border px-3 py-1 {{ $filter === $status ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-100' }}">
                        {{ $status->label() }} · {{ $counts[$status->value] }}
                    </a>
                @endif
            @endforeach
        </div>

        <form method="GET" action="{{ route('campaigns.show', $campaign) }}" class="mt-3 flex flex-wrap items-center gap-2">
            @if ($filter)
                <input type="hidden" name="status" value="{{ $filter->value }}">
            @endif
            <label for="q" class="sr-only">Chercher une adresse</label>
            <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Chercher une adresse"
                class="w-56 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            <button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Chercher</button>
        </form>

        <div class="mt-4 overflow-x-auto rounded-xl border border-stone-200 bg-white">
            @if ($recipients->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-stone-500">
                    {{ $total === 0 ? 'Aucun destinataire pour l\'instant.' : 'Aucun destinataire ne correspond.' }}
                </p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">#</th>
                            <th class="px-4 py-3 font-medium">Adresse</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Réponse</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($recipients as $recipient)
                            @php
                                $date = match ($recipient->status) {
                                    RecipientStatus::Sent => 'Envoyé '.LocalTime::dateTime($recipient->sent_at, $timezone),
                                    RecipientStatus::DryRun => 'Simulé '.LocalTime::dateTime($recipient->sent_at, $timezone),
                                    RecipientStatus::Planned => 'Prévu '.LocalTime::dateTime($recipient->planned_at, $timezone),
                                    RecipientStatus::Sending, RecipientStatus::Uncertain => 'Commencé '.LocalTime::dateTime($recipient->sending_started_at, $timezone),
                                    default => '—',
                                };
                            @endphp
                            <tr class="align-top" data-recipient="{{ $recipient->id }}">
                                <td class="px-4 py-2.5 tabular-nums text-stone-500">{{ $recipient->position }}</td>
                                <td class="px-4 py-2.5 font-mono text-[13px]">{{ $recipient->email }}</td>
                                <td class="px-4 py-2.5">
                                    <x-pill :tone="$recipient->status->tone()">{{ $recipient->status->label() }}</x-pill>
                                    @if ($recipient->last_error)
                                        <p class="mt-1 max-w-xs text-xs text-stone-600" data-reason>{{ SecretScrubber::scrub($recipient->last_error) }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-stone-600">{{ $date }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($recipient->reply_status)
                                        <x-pill :tone="$recipient->reply_status === ReplyStatus::Stop ? 'warn' : 'ok'">{{ $recipient->reply_status->label() }}</x-pill>
                                    @endif
                                    @if ($recipient->notes)
                                        <p class="mt-1 max-w-[16rem] whitespace-pre-line break-words text-xs text-stone-600">{{ \Illuminate\Support\Str::limit($recipient->notes, 160) }}</p>
                                    @endif

                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs font-medium text-indigo-700">Réponse et détails</summary>

                                        <form method="POST" action="{{ route('recipients.reply', [$campaign, $recipient]) }}" class="mt-2 w-64 space-y-2">
                                            @csrf
                                            <label for="notes-{{ $recipient->id }}" class="block text-xs font-medium">Notes</label>
                                            <textarea id="notes-{{ $recipient->id }}" name="notes" rows="3" maxlength="2000"
                                                class="block w-full rounded-lg border border-stone-300 bg-white px-2 py-1 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ $recipient->notes }}</textarea>
                                            <div class="flex flex-wrap gap-1.5">
                                                <button type="submit" name="status" value="replied" class="rounded-md border border-stone-300 bg-white px-2 py-1 text-xs font-medium hover:bg-stone-100">Répondu</button>
                                                <button type="submit" name="status" value="meeting" class="rounded-md border border-stone-300 bg-white px-2 py-1 text-xs font-medium hover:bg-stone-100">Rendez-vous</button>
                                                <button type="submit" name="status" value="stop"
                                                    data-confirm="Ajouter {{ $recipient->email }} à la liste de suppression ? Plus aucun mail ne lui sera envoyé, dans aucune campagne."
                                                    class="rounded-md border border-red-300 bg-white px-2 py-1 text-xs font-medium text-red-800 hover:bg-red-50">Stop</button>
                                            </div>
                                        </form>

                                        @if ($recipient->data)
                                            <dl class="mt-3 w-64 space-y-0.5 text-xs">
                                                @foreach ($recipient->data as $column => $value)
                                                    <div class="flex gap-2"><dt class="shrink-0 font-medium text-stone-500">{{ $column }}</dt><dd class="break-words">{{ $value }}</dd></div>
                                                @endforeach
                                            </dl>
                                        @endif

                                        <p class="mt-3 text-xs"><a href="{{ route('campaigns.journal', [$campaign, 'recipient' => $recipient->id]) }}" class="text-indigo-700 underline">Journal de cette ligne</a></p>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="mt-4">{{ $recipients->links() }}</div>
    </section>
</x-layouts.app>
