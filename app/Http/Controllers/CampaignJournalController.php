<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Le journal d'une campagne : les événements de `send_logs`, les plus récents d'abord. */
class CampaignJournalController extends Controller
{
    public function index(Request $request, Campaign $campaign): View
    {
        $event = trim((string) $request->query('event'));
        $recipientId = is_numeric($request->query('recipient')) ? (int) $request->query('recipient') : null;
        $recipient = $recipientId === null ? null : $campaign->recipients()->find($recipientId);

        $logs = $campaign->logs()
            ->with('recipient:id,email,position')
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            ->when($recipient !== null, fn ($query) => $query->where('recipient_id', $recipient->id))
            // L'identifiant, pas la date : plusieurs événements partagent la même seconde.
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('campaigns.journal', [
            'campaign' => $campaign,
            'logs' => $logs,
            'event' => $event,
            'recipient' => $recipient,
            // Les événements présents dans ce journal, pour le filtre.
            'events' => $campaign->logs()->distinct()->orderBy('event')->pluck('event'),
            'timezone' => $campaign->timezone(),
        ]);
    }
}
