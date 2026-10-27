@php
    use App\Enums\JournalEvent;
    use App\Support\LocalTime;
    use App\Support\SecretScrubber;
@endphp
<x-layouts.app :title="'Journal · '.$campaign->name">
    <x-campaign-header :campaign="$campaign" active="journal" />

    <div class="mt-8 flex flex-wrap items-end justify-between gap-3">
        <h2 class="font-semibold">Journal <span class="font-normal text-stone-500">({{ $logs->total() }})</span></h2>

        <form method="GET" action="{{ route('campaigns.journal', $campaign) }}" class="flex flex-wrap items-center gap-2">
            @if ($recipient)
                <input type="hidden" name="recipient" value="{{ $recipient->id }}">
            @endif
            <label for="event" class="sr-only">Type d'événement</label>
            <select id="event" name="event" onchange="this.form.submit()"
                class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                <option value="">Tous les événements</option>
                @foreach ($events as $name)
                    <option value="{{ $name }}" @selected($event === $name)>{{ JournalEvent::labelFor($name) }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium">Filtrer</button></noscript>
        </form>
    </div>

    @if ($recipient)
        <p class="mt-3 text-sm text-stone-600">
            Seulement la ligne <span class="font-mono">{{ $recipient->email }}</span> ·
            <a href="{{ route('campaigns.journal', array_filter([$campaign, 'event' => $event])) }}" class="text-indigo-700 underline">tout le journal</a>
        </p>
    @endif

    <div class="mt-4 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        @if ($logs->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-stone-500">Rien dans le journal pour l'instant.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Événement</th>
                        <th class="px-4 py-3 font-medium">Ligne</th>
                        <th class="px-4 py-3 font-medium">Détail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($logs as $log)
                        <tr class="align-top" data-log="{{ $log->id }}">
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-stone-600">{{ LocalTime::compact($log->created_at, $timezone) }}</td>
                            <td class="px-4 py-2.5"><x-pill :tone="JournalEvent::toneFor($log->event)">{{ JournalEvent::labelFor($log->event) }}</x-pill></td>
                            <td class="px-4 py-2.5 font-mono text-[13px]">
                                @if ($log->recipient)
                                    <a href="{{ route('campaigns.journal', [$campaign, 'recipient' => $log->recipient->id]) }}" class="text-indigo-700 hover:underline" title="Journal de cette ligne">{{ $log->recipient->email }}</a>
                                @else
                                    <span class="text-stone-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-stone-700">{{ SecretScrubber::scrub((string) $log->detail) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.app>
