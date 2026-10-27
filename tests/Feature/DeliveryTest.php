<?php

use App\Enums\CampaignStatus;
use App\Enums\DeliveryOutcome;
use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Models\Suppression;
use App\Services\Planning\DispatchHistory;
use App\Services\Sending\CampaignLifecycle;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

beforeEach(fn () => sendingEnvironment());

// --- Écartées juste avant l'envoi ---------------------------------------------------------------

it('retire au moment de l\'envoi une adresse ajoutée à la liste de suppression après la planification', function () {
    $campaign = sendingCampaign();
    $a = dueRow($campaign, '2026-10-19 10:00:11');
    $b = dueRow($campaign, '2026-10-19 10:00:12');

    Suppression::create(['value' => 'p1@domaine1.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);

    $report = tickAt('2026-10-19 10:01:00');

    // La ligne écartée n'est pas un envoi : le tick envoie la suivante.
    expect($a->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($a->fresh()->planned_at)->toBeNull()
        ->and($b->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and(sentMail())->toHaveCount(1)
        ->and(sentMail()->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('p2@domaine2.example')
        ->and($report->deliveries)->toHaveCount(2)
        ->and($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Suppressed)
        ->and(journalOf($campaign))->toContain('suppressed');
});

it('retire au moment de l\'envoi une adresse dont le domaine entier a été supprimé', function () {
    $campaign = sendingCampaign();
    $a = dueRow($campaign);

    Suppression::create(['value' => 'domaine1.example', 'type' => SuppressionType::Domain, 'reason' => SuppressionReason::Manual]);

    tickAt('2026-10-19 10:01:00');

    expect($a->fresh()->status)->toBe(RecipientStatus::Suppressed)->and(sentMail())->toHaveCount(0);
});

it('ne tient pas compte de la casse pour la liste de suppression', function () {
    $campaign = sendingCampaign();
    $a = dueRow($campaign);
    $a->forceFill(['email' => 'P1@Domaine1.Example'])->save();

    Suppression::create(['value' => 'p1@domaine1.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);

    tickAt('2026-10-19 10:01:00');

    expect(sentMail())->toHaveCount(0);
});

it('ignore une ligne dont une variable est vide sans valeur par défaut, et envoie la suivante', function () {
    $campaign = sendingCampaign();
    $campaign->update(['subject' => 'Pour [STRUCTURE]']);
    $a = dueRow($campaign, '2026-10-19 10:00:11');
    $a->update(['data' => ['PRENOM' => 'X', 'STRUCTURE' => '']]);
    $b = dueRow($campaign, '2026-10-19 10:00:12');

    $report = tickAt('2026-10-19 10:01:00');
    $a = $a->fresh();

    expect($a->status)->toBe(RecipientStatus::Skipped)
        ->and($a->last_error)->toBe('Valeur vide sans valeur par défaut : [STRUCTURE].')
        ->and($a->planned_at)->toBeNull()
        ->and($b->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Skipped)
        ->and(sentMail())->toHaveCount(1)
        // Rien n'est parti : la ligne ignorée ne compte ni dans le quota, ni dans l'écart.
        ->and(DispatchHistory::load($campaign, 'Europe/Paris')->total())->toBe(1)
        ->and(journalOf($campaign))->toContain('skipped');
});

// --- dry_run ------------------------------------------------------------------------------------

it('en dry_run, tout fonctionne mais rien ne part : rendu gardé, ligne comptée comme envoyée', function () {
    $campaign = sendingCampaign(['dry_run' => true]);
    $a = dueRow($campaign, '2026-10-19 10:00:11');
    $b = dueRow($campaign, '2026-10-19 10:00:12');

    $report = tickAt('2026-10-19 10:01:00');
    $a = $a->fresh();

    expect($report->deliveries)->toHaveCount(1)
        ->and($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::DryRun)
        ->and($report->sent())->toBe(0)
        ->and(sentMail())->toHaveCount(0)
        ->and(ScriptedTransport::$calls)->toBe(0)
        ->and($a->status)->toBe(RecipientStatus::DryRun)
        ->and($a->sent_at->equalTo(paris('2026-10-19 10:01:00')))->toBeTrue()
        ->and($a->rendered_subject)->toBe('Pour Structure 1')
        ->and($a->rendered_body)->toContain('Bonjour Prénom1')
        ->and($a->message_id)->toBeNull()
        ->and(journalOf($campaign))->toContain('dry_run');

    // Elle compte pour l'écart minimum comme un vrai envoi : la suivante attend.
    expect(tickAt('2026-10-19 10:02:00')->deliveries)->toBe([])
        ->and($b->fresh()->status)->toBe(RecipientStatus::Planned)
        ->and(tickAt('2026-10-19 10:26:00')->deliveries[0]['outcome'])->toBe(DeliveryOutcome::DryRun);
});

it('en dry_run, applique quand même les règles d\'envoi : suppression, valeur vide, modèle', function () {
    $campaign = sendingCampaign(['dry_run' => true]);
    $a = dueRow($campaign);
    Suppression::create(['value' => 'p1@domaine1.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);

    tickAt('2026-10-19 10:01:00');

    expect($a->fresh()->status)->toBe(RecipientStatus::Suppressed);
});

// --- La campagne ne peut plus envoyer -----------------------------------------------------------

it('met la campagne en pause, sans rien envoyer, si le modèle a perdu la mention de désinscription', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    $campaign->update(['body' => 'Bonjour [PRENOM|vous]']);

    $report = tickAt('2026-10-19 10:01:00');
    $campaign = $campaign->fresh();

    expect($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->pause_reason)->toContain('désinscription')
        ->and($row->fresh()->status)->toBe(RecipientStatus::Planned)
        ->and($report->paused)->toHaveCount(1)
        ->and(sentMail())->toHaveCount(0)
        ->and(journalOf($campaign))->toContain('paused');
});

it('met la campagne en pause si le modèle utilise une variable qui n\'existe plus', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);
    $campaign->update(['body' => "Bonjour [VILLE]\nstop"]);

    tickAt('2026-10-19 10:01:00');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->fresh()->pause_reason)->toContain('[VILLE]')
        ->and(sentMail())->toHaveCount(0);
});

it('met la campagne en pause si l\'adresse d\'expédition a disparu', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);
    config(['mail.from.address' => null]);

    tickAt('2026-10-19 10:01:00');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->fresh()->pause_reason)->toContain('MAIL_FROM_ADDRESS')
        ->and(sentMail())->toHaveCount(0);
});

// --- Réseau indisponible ------------------------------------------------------------------------

it('réseau indisponible : aucune tentative consommée, disjoncteur intact, la ligne est retentée au tick suivant', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');
    failWith(connectionFailure());

    $report = tickAt('2026-10-19 10:01:00');
    $row = $row->fresh();

    expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::NetworkUnavailable)
        ->and($row->status)->toBe(RecipientStatus::Planned)
        ->and($row->attempts)->toBe(0)
        ->and($row->message_id)->toBeNull()
        ->and($row->rendered_body)->toBeNull()
        ->and($row->sending_started_at)->toBeNull()
        ->and($row->planned_at->equalTo(paris('2026-10-19 10:00:13')))->toBeTrue()
        ->and($row->last_error)->toStartWith('Réseau indisponible')
        ->and($campaign->fresh()->consecutive_failures)->toBe(0);

    // Le tick suivant, puis encore quatre : la ligne est retentée à chaque fois, sans compter d'échec.
    foreach (range(2, 6) as $minute) {
        tickAt("2026-10-19 10:0{$minute}:00");
    }

    expect(ScriptedTransport::$calls)->toBe(6)
        ->and($campaign->fresh()->consecutive_failures)->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($row->fresh()->attempts)->toBe(0)
        // Une panne qui dure ne noie pas le journal : une seule ligne.
        ->and(SendLog::where('campaign_id', $campaign->id)->where('event', 'network')->count())->toBe(1);

    // Le réseau revient.
    ScriptedTransport::$script = null;
    tickAt('2026-10-19 10:07:00');

    $row = $row->fresh();

    expect($row->status)->toBe(RecipientStatus::Sent)
        ->and($row->last_error)->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and(sentMail())->toHaveCount(1);
});

it('réseau indisponible plus longtemps que la tolérance : le créneau est replanifié, pas rattrapé', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');
    failWith(connectionFailure());

    foreach (range(1, 30) as $minute) {
        tickAt(paris('2026-10-19 10:00:00')->addMinutes($minute)->format('Y-m-d H:i:s'));
    }

    expect(ScriptedTransport::$calls)->toBe(30);

    // 10 h 31 : le créneau a plus de 30 minutes de retard. Il est replanifié, et rien n'est tenté dans ce tick.
    tickAt('2026-10-19 10:31:00');
    $row = $row->fresh();

    expect(ScriptedTransport::$calls)->toBe(30)
        ->and($row->status)->toBe(RecipientStatus::Planned)
        ->and($row->planned_at >= paris('2026-10-19 10:32:00') && $row->planned_at <= paris('2026-10-19 17:30:00'))->toBeTrue();

    $replanned = $row->planned_at;
    ScriptedTransport::$script = null;
    $sentAt = null;

    for ($now = paris('2026-10-19 10:32:00'); $now <= paris('2026-10-19 17:31:00') && $sentAt === null; $now = $now->addMinute()) {
        if (tickAt($now->format('Y-m-d H:i:s'))->sent() === 1) {
            $sentAt = $now;
        }
    }

    expect($sentAt)->not->toBeNull()
        ->and($sentAt >= $replanned)->toBeTrue()
        ->and(sentMail())->toHaveCount(1);
});

it('une coupure en plein corps du message, avant le « . » final, est sans risque : on réessaie', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    failWith(connectionFailure('Unable to write bytes on the wire.', smtpTranscript('DATA', '354 go ahead')));

    $report = tickAt('2026-10-19 10:01:00');

    expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::NetworkUnavailable)
        ->and($row->fresh()->status)->toBe(RecipientStatus::Planned);
});

// --- Envoi incertain ----------------------------------------------------------------------------

it('un message dont le « . » final est parti sans réponse devient incertain tout de suite, et n\'est jamais retenté', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    failWith(connectionFailure('Connection to "ssl0.ovh.net:465" timed out.', smtpTranscript('.')));

    $report = tickAt('2026-10-19 10:01:00');
    $row = $row->fresh();

    expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Uncertain)
        ->and($row->status)->toBe(RecipientStatus::Uncertain)
        ->and($row->last_error)->toContain('vos envoyés')
        ->and($row->message_id)->not->toBeNull()
        ->and($row->rendered_subject)->not->toBeNull()
        ->and($campaign->fresh()->consecutive_failures)->toBe(0)
        ->and(journalOf($campaign))->toContain('uncertain');

    foreach (range(2, 90) as $minute) {
        tickAt(paris('2026-10-19 10:00:00')->addMinutes($minute)->format('Y-m-d H:i:s'));
    }

    expect(ScriptedTransport::$calls)->toBe(1)->and($row->fresh()->status)->toBe(RecipientStatus::Uncertain);
});

it('une erreur inconnue pendant l\'envoi rend la ligne incertaine, par prudence', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    failWith(new RuntimeException('Quelque chose d\'inattendu'));

    tickAt('2026-10-19 10:01:00');

    expect($row->fresh()->status)->toBe(RecipientStatus::Uncertain);
});

// --- Échec temporaire : relances ----------------------------------------------------------------

it('échec temporaire : deux nouvelles tentatives à une heure d\'intervalle, puis échec', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');
    failWith(smtpReply(451, '4.7.1 Try again later', 'RCPT TO:<p1@domaine1.example>'));

    $report = tickAt('2026-10-19 10:01:00');
    $first = $row->fresh();

    expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Retry)
        ->and($first->status)->toBe(RecipientStatus::Planned)
        ->and($first->attempts)->toBe(1)
        ->and($first->planned_at->equalTo(paris('2026-10-19 11:01:00')))->toBeTrue()
        ->and($first->last_error)->toContain('Tentative 1/3')->toContain('Try again later')
        ->and($first->message_id)->toBeNull()
        ->and($campaign->fresh()->consecutive_failures)->toBe(1)
        ->and(journalOf($campaign))->toContain('retry');

    // Une minute trop tôt : rien. À l'heure dite : la deuxième tentative.
    tickAt('2026-10-19 11:00:59');
    expect(ScriptedTransport::$calls)->toBe(1);

    tickAt('2026-10-19 11:01:00');
    $second = $row->fresh();

    expect(ScriptedTransport::$calls)->toBe(2)
        ->and($second->attempts)->toBe(2)
        ->and($second->status)->toBe(RecipientStatus::Planned)
        ->and($second->planned_at->equalTo(paris('2026-10-19 12:01:00')))->toBeTrue()
        ->and($campaign->fresh()->consecutive_failures)->toBe(2);

    // Troisième échec : la ligne échoue, et c'est le troisième échec consécutif : la campagne se met en pause.
    $report = tickAt('2026-10-19 12:01:00');
    $third = $row->fresh();
    $campaign = $campaign->fresh();

    expect(ScriptedTransport::$calls)->toBe(3)
        ->and($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Failed)
        ->and($third->status)->toBe(RecipientStatus::Failed)
        ->and($third->attempts)->toBe(3)
        ->and($third->planned_at)->toBeNull()
        ->and($third->last_error)->toContain('Try again later')
        ->and($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->pause_reason)->toContain('Disjoncteur : 3 échecs')->toContain('Try again later')
        ->and($report->paused)->toHaveCount(1)
        ->and(journalOf($campaign))->toContain('failed')->toContain('circuit_breaker');
});

it('une relance qui tomberait hors de la plage est remise en attente pour le lendemain', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 16:44:00');
    failWith(smtpReply(451, '4.7.1 Try again later', 'RCPT TO:<p1@domaine1.example>'));

    tickAt('2026-10-19 16:45:00');

    expect($row->fresh()->planned_at->equalTo(paris('2026-10-19 17:45:00')))->toBeTrue();

    $report = tickAt('2026-10-19 17:45:00');

    expect(ScriptedTransport::$calls)->toBe(1)
        ->and($report->deliveries)->toBe([])
        ->and($row->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($row->fresh()->planned_at)->toBeNull();
});

it('ne tente qu\'un envoi par tick, même quand l\'échec laisse d\'autres lignes dues', function () {
    $campaign = sendingCampaign();
    dueRow($campaign, '2026-10-19 10:00:11');
    $b = dueRow($campaign, '2026-10-19 10:00:12');
    failWith(smtpReply(451, '4.7.1 Try again later', 'RCPT TO:<p1@domaine1.example>'));

    tickAt('2026-10-19 10:01:00');

    expect(ScriptedTransport::$calls)->toBe(1)->and($b->fresh()->status)->toBe(RecipientStatus::Planned);
});

// --- Échec définitif ----------------------------------------------------------------------------

it('refus d\'authentification : la ligne échoue tout de suite, l\'identifiant ne s\'affiche nulle part', function () {
    config(['mail.mailers.smtp.username' => 'prospection@exemple.test', 'mail.mailers.smtp.password' => 'S3cretPassw0rd']);
    $campaign = sendingCampaign();
    $row = dueRow($campaign);

    $e = new TransportException('Failed to authenticate on SMTP server with username "prospection@exemple.test" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "535 5.7.8 Authentication failed S3cretPassw0rd".', 535);
    $e->appendDebug(smtpTranscript('AUTH LOGIN', '535 5.7.8 Authentication failed'));
    failWith($e);

    tickAt('2026-10-19 10:01:00');
    $row = $row->fresh();

    expect($row->status)->toBe(RecipientStatus::Failed)
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('***')->not->toContain('prospection@exemple.test')->not->toContain('S3cretPassw0rd')
        ->and($campaign->fresh()->consecutive_failures)->toBe(1);

    foreach (SendLog::where('campaign_id', $campaign->id)->pluck('detail') as $detail) {
        expect($detail)->not->toContain('prospection@exemple.test')->not->toContain('S3cretPassw0rd');
    }
});

it('trois échecs consécutifs mettent la campagne en pause, la reprise remet le disjoncteur à zéro', function () {
    $campaign = sendingCampaign(rows: 8);
    failWith(smtpReply(554, '5.7.1 Relay access denied', 'MAIL FROM:<moi@exemple.test>'));

    foreach ([1, 2, 3] as $i) {
        dueRow($campaign, "2026-10-19 10:00:1{$i}");
        $report = tickAt("2026-10-19 10:0{$i}:00");

        expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Failed)
            ->and($campaign->fresh()->consecutive_failures)->toBe($i);
    }

    $campaign = $campaign->fresh();

    expect($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->pause_reason)->toContain('Disjoncteur')->toContain('Relay access denied');

    // En pause, plus rien ne part ni ne se tente.
    dueRow($campaign, '2026-10-19 10:04:11');
    $calls = ScriptedTransport::$calls;
    expect(tickAt('2026-10-19 10:05:00')->deliveries)->toBe([])->and(ScriptedTransport::$calls)->toBe($calls);

    // Le problème est réglé : on reprend, et le compteur repart de zéro.
    ScriptedTransport::$script = null;

    expect(app(CampaignLifecycle::class)->resume($campaign))->toBe([]);

    $campaign = $campaign->fresh();
    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->consecutive_failures)->toBe(0)
        ->and($campaign->pause_reason)->toBeNull();

    expect(tickAt('2026-10-19 10:06:00')->sent())->toBe(1);
});

it('un envoi réussi remet le disjoncteur à zéro', function () {
    $campaign = sendingCampaign();
    $campaign->update(['consecutive_failures' => 2]);
    dueRow($campaign);

    tickAt('2026-10-19 10:01:00');

    expect($campaign->fresh()->consecutive_failures)->toBe(0)->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('les pannes de réseau ne déclenchent jamais le disjoncteur', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);
    failWith(connectionFailure());

    foreach (range(1, 12) as $minute) {
        tickAt(paris('2026-10-19 10:00:00')->addMinutes($minute)->format('Y-m-d H:i:s'));
    }

    expect($campaign->fresh()->consecutive_failures)->toBe(0)->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

// --- Rebond -------------------------------------------------------------------------------------

it('adresse refusée par le serveur : rebond, adresse supprimée partout, hors disjoncteur', function () {
    $campaign = sendingCampaign();
    $campaign->update(['consecutive_failures' => 2]);
    $other = sendingCampaign();
    $row = dueRow($campaign);
    $elsewhere = Recipient::where('campaign_id', $other->id)->where('email', 'p1@domaine1.example')->sole();

    failWith(smtpReply(550, '5.1.1 <p1@domaine1.example>: Recipient address rejected: User unknown', 'RCPT TO:<p1@domaine1.example>'));

    $report = tickAt('2026-10-19 10:01:00');
    $row = $row->fresh();

    expect($report->deliveries[0]['outcome'])->toBe(DeliveryOutcome::Bounced)
        ->and($row->status)->toBe(RecipientStatus::Bounced)
        ->and($row->attempts)->toBe(1)
        ->and($row->planned_at)->toBeNull()
        ->and($row->last_error)->toContain('User unknown')
        ->and(Suppression::where('type', 'email')->where('value', 'p1@domaine1.example')->sole()->reason)->toBe(SuppressionReason::Bounce)
        ->and($elsewhere->fresh()->status)->toBe(RecipientStatus::Suppressed)
        // Ni compté, ni remis à zéro : un rebond n'est pas un échec du serveur.
        ->and($campaign->fresh()->consecutive_failures)->toBe(2)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and(journalOf($campaign))->toContain('bounced');
});

it('des rebonds en série ne déclenchent pas le disjoncteur', function () {
    $campaign = sendingCampaign(rows: 8);
    failWith(smtpReply(550, '5.1.1 User unknown', 'RCPT TO:<p1@domaine1.example>'));

    foreach ([1, 2, 3, 4] as $i) {
        dueRow($campaign, "2026-10-19 10:00:1{$i}");
        tickAt("2026-10-19 10:0{$i}:00");
    }

    expect(Recipient::where('campaign_id', $campaign->id)->where('status', 'bounced')->count())->toBe(4)
        ->and($campaign->fresh()->consecutive_failures)->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('une adresse impossible à envoyer fait échouer la ligne, hors disjoncteur', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    failWith(new RfcComplianceException('Email "p1@domaine1.example" does not comply with addr-spec of RFC 2822.'));

    tickAt('2026-10-19 10:01:00');

    expect($row->fresh()->status)->toBe(RecipientStatus::Failed)
        ->and($row->fresh()->last_error)->toStartWith('Adresse invalide')
        ->and($campaign->fresh()->consecutive_failures)->toBe(0);
});

// --- Hygiène ------------------------------------------------------------------------------------

it('ne remonte aucune exception au gestionnaire d\'erreurs : rien dans les journaux de Laravel', function () {
    Exceptions::fake();
    $campaign = sendingCampaign();
    dueRow($campaign);
    failWith(smtpReply(554, '5.7.1 Relay access denied', 'MAIL FROM:<moi@exemple.test>'));

    tickAt('2026-10-19 10:01:00');

    Exceptions::assertNothingReported();
});

it('ne met jamais le corps d\'un mail dans le journal de la campagne', function () {
    $campaign = sendingCampaign(rows: 6);
    dueRow($campaign, '2026-10-19 10:00:11');
    tickAt('2026-10-19 10:01:00');

    failWith(smtpReply(451, '4.7.1 Try again later', 'RCPT TO:<p1@domaine1.example>'));
    dueRow($campaign, '2026-10-19 10:26:11');
    tickAt('2026-10-19 10:27:00');

    $details = SendLog::where('campaign_id', $campaign->id)->pluck('detail')->implode("\n");

    expect($details)->not->toContain('Bonjour')->not->toContain('Prénom')->not->toContain('Structure');
});
