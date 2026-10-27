<?php

namespace App\Http\Controllers;

use App\Enums\ReplyStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Sending\RecipientActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Ce que l'utilisateur fait à la main sur une ligne : des peaux sur RecipientActions. */
class RecipientController extends Controller
{
    public function __construct(private readonly RecipientActions $actions) {}

    public function reply(Request $request, Campaign $campaign, Recipient $recipient): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ReplyStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = ReplyStatus::from($data['status']);

        try {
            $this->actions->markReply($recipient, $status, $this->normalize($data['notes'] ?? null));
        } catch (InvalidArgumentException $e) {
            return $this->back($campaign)->with('recipient_error', $e->getMessage());
        }

        $message = "{$recipient->email} : marqué « {$status->label()} ».";

        if ($status === ReplyStatus::Stop) {
            $message .= ' L\'adresse est dans la liste de suppression : elle ne recevra plus rien.';
        }

        return $this->back($campaign)->with('status', $message);
    }

    public function confirmSent(Campaign $campaign, Recipient $recipient): RedirectResponse
    {
        if (! $this->actions->confirmSent($recipient)) {
            return $this->back($campaign)->with('recipient_error', "{$recipient->email} n'est plus à vérifier.");
        }

        return $this->back($campaign)->with('status', "{$recipient->email} : noté comme envoyé.");
    }

    public function notSent(Campaign $campaign, Recipient $recipient): RedirectResponse
    {
        if (! $this->actions->notSent($recipient)) {
            return $this->back($campaign)->with('recipient_error', "{$recipient->email} n'est plus à vérifier.");
        }

        return $this->back($campaign)->with('status', "{$recipient->email} : remis en attente, il partira à un prochain créneau.");
    }

    private function back(Campaign $campaign): RedirectResponse
    {
        return redirect()->back(fallback: route('campaigns.show', $campaign));
    }

    /** Les navigateurs envoient des fins de ligne Windows ; une zone vide efface les notes. */
    private function normalize(?string $notes): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $notes));
    }
}
