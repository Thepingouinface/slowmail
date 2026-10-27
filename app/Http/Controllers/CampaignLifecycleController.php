<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Sending\CampaignLifecycle;
use Illuminate\Http\RedirectResponse;

/**
 * Les boutons Activer, Mettre en pause, Reprendre : des peaux sur CampaignLifecycle.
 * Un refus rend les raisons telles quelles, à afficher en tête de la campagne.
 */
class CampaignLifecycleController extends Controller
{
    public function __construct(private readonly CampaignLifecycle $lifecycle) {}

    public function activate(Campaign $campaign): RedirectResponse
    {
        return $this->respond($campaign, $this->lifecycle->activate($campaign), 'Campagne activée.');
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        return $this->respond($campaign, $this->lifecycle->resume($campaign), 'Campagne reprise.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        $back = $this->back($campaign);

        if (! $this->lifecycle->pause($campaign, 'Mise en pause à la main.')) {
            return $back->with('lifecycle_problems', ['Seule une campagne active peut être mise en pause.']);
        }

        return $back->with('status', 'Campagne mise en pause.');
    }

    /** @param  list<string>  $problems */
    private function respond(Campaign $campaign, array $problems, string $done): RedirectResponse
    {
        $back = $this->back($campaign);

        if ($problems !== []) {
            return $back->with('lifecycle_problems', $problems);
        }

        if (! config('slowmail.enabled')) {
            return $back->with('status', $done.' Mais SLOWMAIL_ENABLED n\'est pas « true » : aucun mail ne partira tant que le kill switch est coupé.');
        }

        return $back->with('status', $done);
    }

    /** Là d'où vient le clic : le bouton est dans l'en-tête de chaque onglet. */
    private function back(Campaign $campaign): RedirectResponse
    {
        return redirect()->back(fallback: route('campaigns.show', $campaign));
    }
}
