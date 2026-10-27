<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Template\MessageComposer;
use App\Services\Template\TemplateValidator;
use App\Services\Template\TestMailException;
use App\Services\Template\TestMailSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CampaignTemplateController extends Controller
{
    /** Nombre de lignes proposées dans la liste de l'aperçu ; le filtre par adresse permet d'atteindre les autres. */
    private const PREVIEW_CHOICES = 100;

    public function __construct(
        private readonly TemplateValidator $validator,
        private readonly MessageComposer $composer,
        private readonly TestMailSender $tester,
    ) {}

    public function edit(Request $request, Campaign $campaign): View
    {
        $validation = $this->validator->validate($campaign);
        $filter = trim((string) $request->query('q'));

        $choices = $campaign->recipients()
            ->when($filter !== '', fn ($query) => $query->where('email', 'like', '%'.$filter.'%'))
            ->orderBy('position')
            ->limit(self::PREVIEW_CHOICES)
            ->get();

        // La ligne demandée si elle existe, sinon la première du filtre.
        $selected = $this->requestedRecipient($request, $campaign) ?? $choices->first();

        if ($selected !== null && ! $choices->contains('id', $selected->id)) {
            $choices->prepend($selected);
        }

        return view('campaigns.template', [
            'campaign' => $campaign,
            'validation' => $validation,
            'columns' => $campaign->columns ?? [],
            'choices' => $choices,
            'filter' => $filter,
            'selected' => $selected,
            'preview' => $selected !== null && $validation->canRender() ? $this->composer->compose($campaign, $selected) : null,
            'testProblem' => $selected === null ? 'Importez d\'abord des destinataires.' : $this->tester->problem($campaign, $selected),
            'fromAddress' => (string) config('mail.from.address'),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate(
            [
                'subject' => ['nullable', 'string', 'max:300'],
                'body' => ['nullable', 'string', 'max:20000'],
                'recipient' => ['nullable', 'integer'],
            ],
            [
                'subject.max' => 'L\'objet est trop long (300 caractères maximum).',
                'body.max' => 'Le corps est trop long (20 000 caractères maximum).',
            ],
        );

        $campaign->update([
            'subject' => $this->normalize($data['subject'] ?? null),
            'body' => $this->normalize($data['body'] ?? null),
        ]);

        return redirect()
            ->route('campaigns.template', array_filter(['campaign' => $campaign, 'recipient' => $data['recipient'] ?? null]))
            ->with('status', 'Modèle enregistré.');
    }

    public function test(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate(['recipient_id' => ['required', 'integer']]);

        $recipient = $campaign->recipients()->findOrFail($data['recipient_id']);
        $back = redirect()->route('campaigns.template', ['campaign' => $campaign, 'recipient' => $recipient->id]);

        try {
            $to = $this->tester->send($campaign, $recipient);
        } catch (TestMailException $e) {
            return $back->withErrors(['test' => $e->getMessage()]);
        }

        return $back->with('status', "Mail de test envoyé à {$to}.");
    }

    private function requestedRecipient(Request $request, Campaign $campaign): ?Recipient
    {
        $id = $request->query('recipient');

        return is_numeric($id) ? $campaign->recipients()->find((int) $id) : null;
    }

    /** Les navigateurs envoient des fins de ligne Windows : on stocke des « \n », comme pour l'envoi. */
    private function normalize(?string $text): ?string
    {
        return $text === null ? null : str_replace(["\r\n", "\r"], "\n", $text);
    }
}
