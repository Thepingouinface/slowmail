<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Planning\PlanProjector;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CampaignController extends Controller
{
    /** Lignes « à vérifier » détaillées en tête de l'onglet Destinataires ; au-delà, le filtre par statut les liste. */
    private const UNCERTAIN_SHOWN = 20;

    /** Jours de prévision cherchés pour annoncer le prochain envoi d'une campagne. */
    private const NEXT_SEND_HORIZON_DAYS = 21;

    public function index(PlanProjector $projector): View
    {
        $campaigns = Campaign::query()
            ->withCount([
                'recipients',
                'recipients as sent_count' => fn ($query) => $query->where('status', RecipientStatus::Sent),
                'recipients as simulated_count' => fn ($query) => $query->where('status', RecipientStatus::DryRun),
                'recipients as uncertain_count' => fn ($query) => $query->where('status', RecipientStatus::Uncertain),
            ])
            ->latest('id')
            ->get();

        return view('campaigns.index', [
            'campaigns' => $campaigns,
            'nextSends' => $this->nextSends($campaigns, $projector),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:120']],
            ['name.required' => 'Donnez un nom à la campagne.', 'name.max' => 'Le nom est trop long (120 caractères maximum).'],
        );

        $campaign = Campaign::create(['name' => trim($data['name'])]);

        return redirect()->route('campaigns.show', $campaign);
    }

    public function show(Request $request, Campaign $campaign): View
    {
        $counts = $campaign->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $filter = RecipientStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q'));

        $recipients = $campaign->recipients()
            ->when($filter, fn ($query) => $query->where('status', $filter))
            ->when($search !== '', fn ($query) => $query->where('email', 'like', '%'.mb_strtolower($search).'%'))
            ->orderBy('position')
            ->paginate(50)
            ->withQueryString();

        return view('campaigns.show', [
            'campaign' => $campaign,
            'counts' => $counts,
            'total' => $counts->sum(),
            'filter' => $filter,
            'search' => $search,
            'recipients' => $recipients,
            'uncertain' => $campaign->recipients()
                ->where('status', RecipientStatus::Uncertain)
                ->orderBy('sending_started_at')
                ->orderBy('position')
                ->limit(self::UNCERTAIN_SHOWN)
                ->get(),
            'uncertainTotal' => (int) ($counts[RecipientStatus::Uncertain->value] ?? 0),
            'timezone' => $campaign->timezone(),
        ]);
    }

    /**
     * Le prochain envoi de chaque campagne active, tel que le plan le prévoit : l'heure exacte si le plan
     * du jour est tiré, sinon le jour (les heures sont tirées le jour même).
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, string> identifiant de campagne => texte
     */
    private function nextSends(Collection $campaigns, PlanProjector $projector): array
    {
        $now = CarbonImmutable::now();
        $texts = [];

        foreach ($campaigns->where('status', CampaignStatus::Active) as $campaign) {
            $projection = $projector->project($campaign, $now, self::NEXT_SEND_HORIZON_DAYS);
            $day = $projection->days[0] ?? null;

            $texts[$campaign->id] = match (true) {
                $projection->blocked !== null => 'Réglages invalides : rien ne partira',
                $day === null => 'Rien de prévu pour l\'instant',
                $day->exact && ($time = $day->entries[0]['time'] ?? null) !== null => LocalTime::dateTime($time, $campaign->timezone()),
                default => LocalTime::day($day->date, $campaign->timezone()).' (heure tirée le jour même)',
            };
        }

        return $texts;
    }
}
