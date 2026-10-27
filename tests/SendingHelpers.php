<?php

use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Sending\TickReport;
use App\Services\Sending\TickRunner;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

/**
 * Un transport de test qui garde les messages comme le transport « array », mais qui peut échouer à la demande :
 * le script reçoit le message et peut lever l'exception que Symfony Mailer lèverait. Aucun envoi réel.
 */
final class ScriptedTransport extends ArrayTransport
{
    /** @var ?Closure(RawMessage): void */
    public static ?Closure $script = null;

    /** Nombre d'appels d'envoi, réussis ou non. */
    public static int $calls = 0;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        self::$calls++;

        if (self::$script !== null) {
            (self::$script)($message);
        }

        return parent::send($message, $envelope);
    }
}

/** Le mail passe par le transport scriptable. Sans script, l'envoi réussit. */
function scriptedMail(?Closure $script = null): void
{
    ScriptedTransport::$script = $script;
    ScriptedTransport::$calls = 0;

    Mail::extend('scripted', fn () => new ScriptedTransport);
    config(['mail.default' => 'scripted', 'mail.mailers.scripted' => ['transport' => 'scripted']]);
}

/** Les prochains envois échouent avec cette exception, jusqu'à ce que `ScriptedTransport::$script` soit remis à null. */
function failWith(Throwable $e): void
{
    ScriptedTransport::$script = fn () => throw $e;
}

/** Les messages réellement « partis » par le transport scriptable. */
function sentMail(): Collection
{
    return Mail::mailer()->getSymfonyTransport()->messages();
}

/** Prépare un environnement où l'envoi est permis : kill switch ouvert, adresse d'expédition, transport scriptable. */
function sendingEnvironment(): void
{
    config([
        'slowmail.enabled' => true,
        'mail.from.address' => 'moi@exemple.test',
        'mail.from.name' => 'Matthieu',
    ]);

    scriptedMail();
}

/**
 * Une campagne active prête à envoyer : modèle valide, un destinataire par domaine, avec ses données.
 *
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $attributes
 */
function sendingCampaign(array $settings = [], int $rows = 5, array $attributes = []): Campaign
{
    $campaign = campaign('Envoi');
    $campaign->update(array_merge([
        'subject' => 'Pour [STRUCTURE|votre structure]',
        'body' => "Bonjour [PRENOM|vous],\n\nNous sommes le [DATE].\n\nPour ne plus recevoir de mails, répondez STOP.",
        'columns' => ['PRENOM', 'STRUCTURE'],
        'status' => 'active',
        'settings' => $settings === [] ? null : $settings,
    ], $attributes));

    // range(1, 0) vaut [1, 0] en PHP : pas de destinataire se demande explicitement.
    for ($i = 1; $i <= $rows; $i++) {
        recipient($campaign, "p{$i}@domaine{$i}.example", data: ['PRENOM' => "Prénom{$i}", 'STRUCTURE' => "Structure {$i}"]);
    }

    return $campaign->fresh();
}

/**
 * Le $index-ième destinataire en attente, planifié à l'heure donnée, plan du jour déjà tiré : il est dû dès cette heure.
 */
function dueRow(Campaign $campaign, string $at = '2026-10-19 10:00:13', int $index = 0): Recipient
{
    $row = Recipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('status', 'pending')
        ->orderBy('position')
        ->skip($index)
        ->firstOrFail();

    planAt($row, $at);
    markDrawn($campaign, paris($at)->format('Y-m-d'));

    return $row->fresh();
}

/** Un tick du scheduler à l'heure donnée (heure de Paris), avec un hasard reproductible pour le tirage du plan. */
function tickAt(string $time, int $seed = 1): TickReport
{
    test()->travelTo(paris($time));
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937($seed)));

    return app()->make(TickRunner::class)->run();
}

/** Les événements du journal d'une campagne, dans l'ordre. */
function journalOf(Campaign $campaign): array
{
    return SendLog::query()->where('campaign_id', $campaign->id)->orderBy('id')->pluck('event')->all();
}

/** Le serveur SMTP a répondu par une erreur à la commande donnée, avec le journal de conversation que Symfony garde. */
function smtpReply(int $code, string $reply, string $command): UnexpectedResponseException
{
    $e = new UnexpectedResponseException(sprintf('Expected response code "250" but got code "%d", with message "%d %s".', $code, $code, $reply), $code);
    $e->appendDebug(smtpTranscript($command, "{$code} {$reply}"));

    return $e;
}

/**
 * La conversation SMTP telle que Symfony la journalise, jusqu'à la commande donnée et la réponse du serveur.
 * Le corps du message n'y figure pas : seule la commande `.` qui le termine y est.
 */
function smtpTranscript(string $lastCommand, ?string $lastReply = null): string
{
    $lines = ['[t] < 220 smtp.exemple.test ESMTP', '[t] > EHLO local', '[t] < 250 ok'];

    $commands = ['MAIL FROM:<moi@exemple.test>', 'RCPT TO:<p1@domaine1.example>', 'DATA', '.'];
    $reached = array_search($lastCommand, $commands, true);

    foreach ($reached === false ? [$lastCommand] : array_slice($commands, 0, $reached + 1) as $command) {
        $lines[] = "[t] > {$command}";

        if ($command !== $lastCommand) {
            $lines[] = $command === 'DATA' ? '[t] < 354 go ahead' : '[t] < 250 ok';
        }
    }

    if ($lastReply !== null) {
        $lines[] = "[t] < {$lastReply}";
    }

    return implode("\n", $lines)."\n";
}

/** Pas de réseau, ou une connexion coupée : aucune réponse du serveur. */
function connectionFailure(string $message = 'Connection could not be established with host "ssl0.ovh.net:465": stream_socket_client(): php_network_getaddresses: getaddrinfo failed', string $debug = ''): TransportException
{
    $e = new TransportException($message);

    if ($debug !== '') {
        $e->appendDebug($debug);
    }

    return $e;
}

/** @return Recipient[] */
function rowsOf(Campaign $campaign): array
{
    return Recipient::query()->where('campaign_id', $campaign->id)->orderBy('position')->get()->all();
}
