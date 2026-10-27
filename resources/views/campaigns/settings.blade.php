@php
    use App\Enums\CampaignStatus;
    use App\Support\LocalTime;

    $old = session()->hasOldInput();
    $input = fn (string $key, mixed $saved) => old($key, $saved);
    // Une case décochée n'est pas envoyée : après une erreur, « rien d'envoyé » veut dire décochée.
    $checked = fn (string $key, bool $saved) => $old ? old($key) !== null : $saved;
    $dayChecked = fn (int $day) => $old ? in_array((string) $day, array_map('strval', (array) old('days', [])), true) : in_array($day, $settings->days, true);

    $active = $campaign->status === CampaignStatus::Active;
    $field = 'mt-1 block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:bg-stone-100';
    $dayNames = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
@endphp
<x-layouts.app :title="'Réglages · '.$campaign->name">
    <x-campaign-header :campaign="$campaign" active="settings" />

    <div class="mt-8 grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($errors->has('settings'))
                <x-alert tone="error" role="alert" class="mb-6" data-settings-errors>
                    <p class="font-medium">Les réglages n'ont pas été enregistrés :</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        @foreach ($errors->get('settings') as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            @if ($locked)
                <x-alert tone="info" class="mb-6">La campagne est terminée : ses réglages ne changent plus.</x-alert>
            @elseif ($active)
                <x-alert tone="warn" class="mb-6">
                    La campagne est active : un changement s'applique tout de suite. Les créneaux du jour non encore envoyés sont remis en attente,
                    et le prochain passage en tire de nouveaux avec les réglages enregistrés.
                </x-alert>
            @endif

            <form method="POST" action="{{ route('campaigns.settings.update', $campaign) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <fieldset @disabled($locked) class="space-y-6">
                    <section class="rounded-xl border border-stone-200 bg-white p-5">
                        <h2 class="font-semibold">Rythme</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="daily_max" class="block text-sm font-medium">Mails par jour, au plus</label>
                                <input id="daily_max" name="daily_max" type="number" min="1" max="{{ App\Services\Planning\CampaignSettings::MAX_DAILY }}" required value="{{ $input('daily_max', $settings->dailyMax) }}" class="{{ $field }}">
                                @error('daily_max')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="min_gap_minutes" class="block text-sm font-medium">Écart minimum entre deux mails (minutes)</label>
                                <input id="min_gap_minutes" name="min_gap_minutes" type="number" min="0" max="720" required value="{{ $input('min_gap_minutes', $settings->minGapMinutes) }}" class="{{ $field }}">
                                @error('min_gap_minutes')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="domain_cooldown_days" class="block text-sm font-medium">Délai entre deux mails à un même domaine (jours)</label>
                                <input id="domain_cooldown_days" name="domain_cooldown_days" type="number" min="0" max="365" required value="{{ $input('domain_cooldown_days', $settings->domainCooldownDays) }}" class="{{ $field }}">
                                <p class="mt-1 text-xs text-stone-500">0 : aucun délai. Évite d'écrire à dix agences d'un même réseau le même jour.</p>
                                @error('domain_cooldown_days')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="max_total" class="block text-sm font-medium">Arrêt après N mails (facultatif)</label>
                                <input id="max_total" name="max_total" type="number" min="1" value="{{ $input('max_total', $settings->maxTotal) }}" class="{{ $field }}">
                                <p class="mt-1 text-xs text-stone-500">Vide : pas de maximum.</p>
                                @error('max_total')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <label class="mt-4 flex items-start gap-2 text-sm">
                            <input type="checkbox" name="jitter" value="1" @checked($checked('jitter', $settings->jitter)) class="mt-0.5 rounded border-stone-300">
                            <span><span class="font-medium">Heures aléatoires</span> : jamais pile sur l'heure ronde. Décoché, les heures sont régulières dans la plage.</span>
                        </label>
                    </section>

                    <section class="rounded-xl border border-stone-200 bg-white p-5">
                        <h2 class="font-semibold">Plage et jours d'envoi</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="window_start" class="block text-sm font-medium">De</label>
                                <input id="window_start" name="window_start" type="time" required value="{{ $input('window_start', $settings->windowStart) }}" class="{{ $field }}">
                                @error('window_start')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="window_end" class="block text-sm font-medium">À</label>
                                <input id="window_end" name="window_end" type="time" required value="{{ $input('window_end', $settings->windowEnd) }}" class="{{ $field }}">
                                @error('window_end')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="timezone" class="block text-sm font-medium">Fuseau horaire</label>
                                <input id="timezone" name="timezone" type="text" list="timezones" required maxlength="64" value="{{ $input('timezone', $settings->timezone) }}" class="{{ $field }} font-mono text-sm">
                                <datalist id="timezones">
                                    @foreach (DateTimeZone::listIdentifiers() as $identifier)
                                        <option value="{{ $identifier }}"></option>
                                    @endforeach
                                </datalist>
                                @error('timezone')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <fieldset class="mt-4">
                            <legend class="text-sm font-medium">Jours autorisés</legend>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                                @foreach ($dayNames as $number => $name)
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" name="days[]" value="{{ $number }}" @checked($dayChecked($number)) class="rounded border-stone-300"> {{ $name }}
                                    </label>
                                @endforeach
                            </div>
                            @error('days')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </fieldset>

                        <label class="mt-4 flex items-start gap-2 text-sm">
                            <input type="checkbox" name="skip_holidays" value="1" @checked($checked('skip_holidays', $settings->skipHolidays)) class="mt-0.5 rounded border-stone-300">
                            <span><span class="font-medium">Pas d'envoi les jours fériés français</span> (calculés, hors Alsace-Moselle).</span>
                        </label>
                    </section>

                    <section class="rounded-xl border border-stone-200 bg-white p-5">
                        <h2 class="font-semibold">Dates de la campagne</h2>
                        <p class="mt-1 text-sm text-stone-500">À l'heure du fuseau ci-dessus. Vides : la campagne démarre dès qu'elle est activée et ne s'arrête que quand il n'y a plus rien à envoyer.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="start_at" class="block text-sm font-medium">Début</label>
                                <input id="start_at" name="start_at" type="datetime-local" value="{{ $input('start_at', LocalTime::input($campaign->start_at, $timezone)) }}" class="{{ $field }}">
                                @error('start_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="end_at" class="block text-sm font-medium">Fin</label>
                                <input id="end_at" name="end_at" type="datetime-local" value="{{ $input('end_at', LocalTime::input($campaign->end_at, $timezone)) }}" class="{{ $field }}">
                                <p class="mt-1 text-xs text-stone-500">La campagne s'arrête à cette date, même s'il reste des lignes.</p>
                                @error('end_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-stone-200 bg-white p-5">
                        <h2 class="font-semibold">Montée progressive</h2>
                        <label class="mt-3 flex items-start gap-2 text-sm">
                            <input type="checkbox" name="ramp_up" value="1" @checked($checked('ramp_up', $settings->rampUp)) class="mt-0.5 rounded border-stone-300">
                            <span><span class="font-medium">Commencer doucement</span>, puis monter jusqu'au maximum par jour. Seuls les jours où des mails sont réellement partis comptent.</span>
                        </label>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="ramp_start" class="block text-sm font-medium">Mails par jour au départ</label>
                                <input id="ramp_start" name="ramp_start" type="number" min="1" value="{{ $input('ramp_start', $settings->rampStart) }}" class="{{ $field }}">
                                @error('ramp_start')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="ramp_step" class="block text-sm font-medium">Palier (mails en plus)</label>
                                <input id="ramp_step" name="ramp_step" type="number" min="0" value="{{ $input('ramp_step', $settings->rampStep) }}" class="{{ $field }}">
                                @error('ramp_step')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="ramp_every_days" class="block text-sm font-medium">Tous les (jours d'envoi)</label>
                                <input id="ramp_every_days" name="ramp_every_days" type="number" min="1" value="{{ $input('ramp_every_days', $settings->rampEveryDays) }}" class="{{ $field }}">
                                @error('ramp_every_days')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-5">
                        <h2 class="font-semibold">Simulation</h2>
                        @if ($active)
                            <input type="hidden" name="dry_run" value="{{ $settings->dryRun ? 1 : 0 }}">
                            <label class="mt-3 flex items-start gap-2 text-sm">
                                <input type="checkbox" disabled @checked($settings->dryRun) class="mt-0.5 rounded border-stone-300">
                                <span><span class="font-medium">Mode simulation</span> : tout fonctionne, aucun mail ne part. <em>Pour changer de mode, mettez d'abord la campagne en pause.</em></span>
                            </label>
                        @else
                            <label class="mt-3 flex items-start gap-2 text-sm">
                                <input type="checkbox" name="dry_run" value="1" @checked($checked('dry_run', $settings->dryRun)) class="mt-0.5 rounded border-stone-300">
                                <span><span class="font-medium">Mode simulation</span> : tout fonctionne (plan, rendu, quota, journal), mais aucun mail ne part. Les lignes passent à « Simulé ».</span>
                            </label>
                        @endif
                        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-indigo-950">
                            <li>On ne change pas de mode sur une campagne active : mettez-la en pause, changez, puis « Reprendre » (la confirmation dit si de vrais mails vont partir).</li>
                            <li>Les lignes déjà simulées restent « Simulé » quand on coupe la simulation : elles ne partiront pas, pour qu'un réglage ne déclenche jamais d'envoi réel par surprise. Le bouton « Remettre en attente » ci-contre les rend envoyables.</li>
                        </ul>
                    </section>
                </fieldset>

                @unless ($locked)
                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Enregistrer les réglages</button>
                    </div>
                @endunless
            </form>
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5" data-recap>
                <h2 class="font-semibold">Récapitulatif</h2>
                <p class="mt-1 text-xs text-stone-500">Réglages enregistrés.</p>
                <p class="mt-3 text-sm leading-relaxed" data-recap-summary>{{ $settings->summary() }}</p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-stone-500">Mode</dt>
                        <dd>{{ $settings->dryRun ? 'Simulation : aucun mail ne part.' : 'Réel : de vrais mails partent.' }}</dd>
                    </div>
                    @if ($campaign->start_at)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-stone-500">Début</dt>
                            <dd>{{ LocalTime::dateTime($campaign->start_at, $timezone) }}</dd>
                        </div>
                    @endif
                    @if ($campaign->end_at)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-stone-500">Fin</dt>
                            <dd>{{ LocalTime::dateTime($campaign->end_at, $timezone) }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-stone-500">Fin estimée</dt>
                        <dd data-recap-end>
                            @if ($projection->blocked !== null)
                                Impossible à prévoir : {{ $projection->blocked }}
                            @elseif ($projection->estimatedEnd !== null)
                                {{ $projection->estimatedEnd->locale('fr')->isoFormat('dddd D MMMM YYYY') }}, au rythme actuel
                                ({{ count($projection->days) }} jour(s) d'envoi, {{ $projection->totalPlanned() }} mail(s) à envoyer).
                            @elseif ($projection->stoppedBy === 'end_at')
                                La date de fin arrêtera la campagne : {{ $projection->leftOver }} ligne(s) ne seront pas contactées.
                            @elseif ($projection->days === [])
                                Rien à envoyer pour l'instant.
                            @else
                                Au-delà de l'horizon de prévision.
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($projection->days !== [])
                    <div class="mt-4">
                        <h3 class="text-xs uppercase tracking-wide text-stone-500">Prochains jours d'envoi</h3>
                        <ul class="mt-2 space-y-1 text-sm" data-recap-days>
                            @foreach (array_slice($projection->days, 0, 7) as $day)
                                <li>
                                    <span class="font-medium">{{ LocalTime::day($day->date, $timezone) }}</span> :
                                    {{ count($day->entries) }} mail(s)
                                    @if ($day->exact)
                                        <span class="text-stone-500">({{ collect($day->entries)->map(fn ($entry) => LocalTime::clock($entry['time'], $timezone))->implode(', ') }})</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-stone-500">Les heures des jours à venir sont tirées le jour même.</p>
                    </div>
                @endif
            </section>

            @if ($simulated > 0)
                <section class="rounded-xl border border-indigo-200 bg-white p-5" data-simulated>
                    <h2 class="font-semibold">Lignes simulées</h2>
                    <p class="mt-2 text-sm">{{ $simulated }} ligne(s) au statut « Simulé » : rien n'est parti, mais elles comptent comme des envois pour le quota et ne partiront pas toutes seules.</p>

                    @if ($campaign->status === CampaignStatus::Draft || $campaign->status === CampaignStatus::Paused)
                        <form method="POST" action="{{ route('campaigns.settings.release-simulated', $campaign) }}" class="mt-3"
                            data-confirm="Remettre {{ $simulated }} ligne(s) simulée(s) en attente ? Une fois la simulation coupée, elles partiront pour de vrai.">
                            @csrf
                            <button type="submit" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Remettre en attente</button>
                        </form>
                    @else
                        <p class="mt-2 text-xs text-stone-500">Mettez la campagne en pause pour pouvoir les remettre en attente.</p>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
