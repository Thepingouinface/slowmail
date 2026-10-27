<?php

namespace App\Http\Controllers;

use App\Enums\SuppressionReason;
use App\Models\Suppression;
use App\Services\Sending\Suppressions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * La liste de suppression : consultation et ajout. Pas de retrait depuis l'interface, volontairement :
 * une adresse retirée par un clic malheureux recevrait de nouveau des mails. Le retrait se fait à la main, en base.
 */
class SuppressionController extends Controller
{
    public function __construct(private readonly Suppressions $suppressions) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $reason = SuppressionReason::tryFrom((string) $request->query('reason'));

        return view('suppressions.index', [
            'suppressions' => Suppression::query()
                ->when($search !== '', fn ($query) => $query->where('value', 'like', '%'.mb_strtolower(ltrim($search, '@')).'%'))
                ->when($reason, fn ($query) => $query->where('reason', $reason))
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'total' => Suppression::count(),
            'search' => $search,
            'reason' => $reason,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['required', Rule::enum(SuppressionReason::class)],
        ]);

        try {
            $result = $this->suppressions->add($data['value'], SuppressionReason::from($data['reason']));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('suppressions.index')->withInput()->withErrors(['value' => $e->getMessage()]);
        }

        $value = ($result->suppression->type->value === 'domain' ? '@' : '').$result->suppression->value;

        $message = $result->created
            ? "{$value} ajouté à la liste de suppression."
            : "{$value} était déjà dans la liste de suppression ({$result->suppression->reason->label()}).";

        if ($result->affected > 0) {
            $message .= " {$result->affected} ligne(s) en attente ou planifiée(s) retirée(s) de l'envoi.";
        }

        return redirect()->route('suppressions.index')->with('status', $message);
    }
}
