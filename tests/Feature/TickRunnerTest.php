<?php

use App\Enums\CampaignStatus;
use App\Enums\DeliveryOutcome;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Planning\DispatchHistory;
use App\Services\Sending\CampaignLifecycle;
use App\Services\Sending\DeliveryService;
use App\Services\Sending\SchedulerState;
use App\Services\Sending\TickRunner;
use App\Services\Template\MessageComposer;
use Illuminate\Support\Facades\Cache;

// Le lundi 19 octobre 2026 est un jour d'envoi ordinaire. Tous les créneaux sont posés à la main :
// le hasard du tirage n'intervient pas, sauf là où le test le dit.

beforeEach(fn () => sendingEnvironment());

// --- Un envoi -----------------------------------------------------------------------------------

it('envoie le mail dû, une seule fois, et garde ce qui est parti', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');

    $report = tickAt('2026-10-19 10:01:00');
    $row = $row->fresh();

    expect($report->sent())->toBe(1)
        ->and($row->status)->toBe(RecipientStatus::Sent)
        ->and($row->sent_at->equalTo(paris('2026-10-19 10:01:00')))->toBeTrue()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBeNull()
        ->and($row->message_id)->toMatch('/^[0-9a-f]{32}@exemple\.test$/')
        ->and($row->rendered_subject)->toBe('Pour Structure 1')
        ->and($row->rendered_body)->toContain('Bonjour Prénom1')->toContain('19 octobre 2026')
        ->and($campaign->fresh()->consecutive_failures)->toBe(0)
        ->and(journalOf($campaign))->toContain('sent');

    expect(sentMail())->toHaveCount(1);

    $email = sentMail()->first()->getOriginalMessage();

    expect($email->getTo()[0]->getAddress())->toBe('p1@domaine1.example')
        ->and($email->getFrom()[0]->getAddress())->toBe('moi@exemple.test')
        ->and($email->getSubject())->toBe($row->rendered_subject)
        ->and($email->getTextBody())->toBe($row->rendered_body)
        ->and($email->getHtmlBody())->toBeNull()
        ->and($email->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<'.$row->message_id.'>')
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toBe('<mailto:moi@exemple.test>');
});

it('ne renvoie jamais une ligne déjà envoyée, tick après tick', function () {
    $campaign = sendingCampaign();
    dueRow($campaign, '2026-10-19 10:00:13');

    $sent = 0;

    foreach (range(1, 120) as $minute) {
        $sent += tickAt(paris('2026-10-19 10:00:00')->addMinutes($minute)->format('Y-m-d H:i:s'))->sent();
    }

    expect($sent)->toBe(1)->and(sentMail())->toHaveCount(1);
});

it('n\'envoie qu\'un mail par tick, jamais en rafale, même quand plusieurs sont dus', function () {
    $campaign = sendingCampaign();
    $a = dueRow($campaign, '2026-10-19 10:00:11');
    $b = dueRow($campaign, '2026-10-19 10:00:12');
    $c = dueRow($campaign, '2026-10-19 10:00:13');

    expect(tickAt('2026-10-19 10:01:00')->sent())->toBe(1)
        ->and(tickAt('2026-10-19 10:02:00')->sent())->toBe(0)
        ->and(tickAt('2026-10-19 10:25:59')->sent())->toBe(0)
        ->and(tickAt('2026-10-19 10:26:00')->sent())->toBe(1)
        ->and(sentMail())->toHaveCount(2)
        ->and($a->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and($b->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and($c->fresh()->status)->toBe(RecipientStatus::Planned);
});

it('ne fait rien quand rien n\'est dû, mais note son passage et le prochain envoi prévu', function () {
    sendingCampaign();

    $report = tickAt('2026-10-19 07:00:00');
    $state = app(SchedulerState::class);

    expect($report->deliveries)->toBe([])
        ->and(sentMail())->toHaveCount(0)
        ->and($state->lastTickAt()->equalTo(paris('2026-10-19 07:00:00')))->toBeTrue()
        // Le plan du jour vient d'être tiré : le prochain envoi est le premier créneau.
        ->and($state->nextSendAt()->format('Y-m-d H:i:s'))->toBe(Recipient::where('status', 'planned')->min('planned_at'));
});

it('annonce l\'ouverture de la prochaine plage quand plus rien n\'est prévu aujourd\'hui', function () {
    $campaign = sendingCampaign();
    markDrawn($campaign, '2026-10-19');

    tickAt('2026-10-19 21:00:00');

    expect(app(SchedulerState::class)->nextSendAt()->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-10-20 09:30');
});

it('retient le premier créneau planifié comme prochain envoi', function () {
    $campaign = sendingCampaign();
    dueRow($campaign, '2026-10-19 15:00:13');

    tickAt('2026-10-19 10:01:00');

    expect(app(SchedulerState::class)->nextSendAt()->setTimezone('Europe/Paris')->format('H:i:s'))->toBe('15:00:13');
});

it('ne traite que les campagnes actives', function () {
    foreach (['draft', 'paused', 'finished'] as $status) {
        $campaign = sendingCampaign(attributes: ['status' => $status]);
        dueRow($campaign);
    }

    $report = tickAt('2026-10-19 10:01:00');

    expect($report->deliveries)->toBe([])->and(sentMail())->toHaveCount(0);
});

it('envoie un mail au plus par tick sur l\'ensemble des campagnes actives', function () {
    $a = sendingCampaign();
    $b = sendingCampaign();
    dueRow($a);
    dueRow($b);

    $first = tickAt('2026-10-19 10:01:00');
    $second = tickAt('2026-10-19 10:02:00');

    expect($first->sent())->toBe(1)->and($first->deliveries[0]['campaign'])->toBe($a->id)
        ->and($second->sent())->toBe(1)->and($second->deliveries[0]['campaign'])->toBe($b->id)
        ->and(sentMail())->toHaveCount(2);
});

// --- Kill switch --------------------------------------------------------------------------------

it('coupé par le kill switch, rien ne part ni ne se planifie, mais le passage est noté', function () {
    config(['slowmail.enabled' => false]);
    $campaign = sendingCampaign();
    $due = dueRow($campaign);

    $report = tickAt('2026-10-19 10:01:00');

    expect($report->disabled)->toBeTrue()
        ->and($report->sent())->toBe(0)
        ->and(sentMail())->toHaveCount(0)
        ->and($due->fresh()->status)->toBe(RecipientStatus::Planned)
        ->and(app(SchedulerState::class)->lastTickAt())->not->toBeNull();
});

it('coupé par le kill switch, signale quand même les envois interrompus', function () {
    config(['slowmail.enabled' => false]);
    $campaign = sendingCampaign();
    $stuck = Recipient::where('campaign_id', $campaign->id)->first();
    $stuck->forceFill(['status' => RecipientStatus::Sending, 'sending_started_at' => paris('2026-10-19 09:30')->utc()])->save();

    $report = tickAt('2026-10-19 10:01:00');

    expect($report->uncertain)->toBe(1)->and($stuck->fresh()->status)->toBe(RecipientStatus::Uncertain);
});

it('le kill switch bloque l\'envoi aussitôt, même pour une ligne déjà due', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    config(['slowmail.enabled' => false]);

    $delivery = app(DeliveryService::class)->deliver($campaign, $row, paris('2026-10-19 10:01:00'));

    expect($delivery->outcome)->toBe(DeliveryOutcome::Disabled)
        ->and(sentMail())->toHaveCount(0)
        ->and($row->fresh()->status)->toBe(RecipientStatus::Planned);
});

// --- Fin et pause de la campagne ----------------------------------------------------------------

it('termine la campagne quand il n\'y a plus de destinataire', function () {
    $campaign = sendingCampaign(rows: 1);
    markSent(Recipient::where('campaign_id', $campaign->id)->first(), paris('2026-10-16 10:00'));

    $report = tickAt('2026-10-19 07:00:00');
    $campaign = $campaign->fresh();

    expect($report->finished)->toHaveCount(1)
        ->and($campaign->status)->toBe(CampaignStatus::Finished)
        ->and($campaign->finished_at->equalTo(paris('2026-10-19 07:00:00')))->toBeTrue()
        ->and(journalOf($campaign))->toContain('finished');
});

it('termine la campagne au maximum total', function () {
    $campaign = sendingCampaign(['max_total' => 1]);
    markSent(Recipient::where('campaign_id', $campaign->id)->first(), paris('2026-10-16 10:00'));

    tickAt('2026-10-19 07:00:00');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Finished);
});

it('met la campagne en pause, avec la raison, quand ses réglages sont invalides', function () {
    $campaign = sendingCampaign(['daily_max' => 0]);

    $report = tickAt('2026-10-19 07:00:00');
    $campaign = $campaign->fresh();

    expect($report->paused)->toHaveCount(1)
        ->and($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->pause_reason)->toContain('Réglages invalides')
        ->and(journalOf($campaign))->toContain('paused')
        ->and(sentMail())->toHaveCount(0);
});

it('ne touche plus à une campagne mise en pause', function () {
    $campaign = sendingCampaign(['daily_max' => 0]);
    tickAt('2026-10-19 07:00:00');
    $campaign->fresh()->update(['settings' => null]);
    dueRow($campaign->fresh());

    expect(tickAt('2026-10-19 10:01:00')->deliveries)->toBe([])->and(sentMail())->toHaveCount(0);
});

// --- Interruption -------------------------------------------------------------------------------

it('note une interruption dans le journal des campagnes actives quand le scheduler a manqué plus de 15 minutes', function () {
    $active = sendingCampaign();
    $draft = sendingCampaign(attributes: ['status' => 'draft']);

    $premier = tickAt('2026-10-19 08:00:00');
    expect($premier->interruptionMinutes)->toBeNull()
        ->and(SendLog::where('event', 'interruption')->count())->toBe(0);

    expect(tickAt('2026-10-19 08:15:00')->interruptionMinutes)->toBeNull();

    $report = tickAt('2026-10-19 14:27:00');
    $log = SendLog::where('event', 'interruption')->sole();

    expect($report->interruptionMinutes)->toBe(372)
        ->and($log->campaign_id)->toBe($active->id)
        ->and($log->detail)->toContain('6 h 12 min')
        ->and(journalOf($draft))->not->toContain('interruption');
});

it('note une interruption dès 16 minutes, pas à 15', function () {
    sendingCampaign();

    tickAt('2026-10-19 08:00:00');
    tickAt('2026-10-19 08:15:00');
    expect(SendLog::where('event', 'interruption')->count())->toBe(0);

    tickAt('2026-10-19 08:31:00');
    expect(SendLog::where('event', 'interruption')->sole()->detail)->toContain('16 min');
});

it('compte l\'interruption même quand le kill switch est coupé', function () {
    config(['slowmail.enabled' => false]);
    sendingCampaign();

    tickAt('2026-10-19 08:00:00');
    $report = tickAt('2026-10-19 12:00:00');

    expect($report->interruptionMinutes)->toBe(240);
});

// --- Envois interrompus en plein vol ------------------------------------------------------------

it('passe en incertain une ligne restée en sending depuis plus de 10 minutes, et pas avant', function () {
    $campaign = sendingCampaign();
    $row = Recipient::where('campaign_id', $campaign->id)->first();
    $row->forceFill(['status' => RecipientStatus::Sending, 'sending_started_at' => paris('2026-10-19 10:00:00')->utc(), 'message_id' => 'abc@exemple.test'])->save();
    markDrawn($campaign, '2026-10-19');

    tickAt('2026-10-19 10:09:59');
    expect($row->fresh()->status)->toBe(RecipientStatus::Sending);

    tickAt('2026-10-19 10:10:00');
    expect($row->fresh()->status)->toBe(RecipientStatus::Sending);

    $report = tickAt('2026-10-19 10:10:01');

    expect($row->fresh()->status)->toBe(RecipientStatus::Uncertain)
        ->and($row->fresh()->last_error)->toContain('vos envoyés')
        ->and($row->fresh()->message_id)->toBe('abc@exemple.test')
        ->and($report->uncertain)->toBe(1)
        ->and(journalOf($campaign))->toContain('uncertain');
});

it('un crash entre le passage en sending et la réponse SMTP : la ligne devient incertaine et n\'est jamais renvoyée', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');
    $moment = paris('2026-10-19 10:01:00');

    // La ligne est réclamée (transaction validée) ; la machine s'arrête avant que le mail ne parte.
    $message = app(MessageComposer::class)->compose($campaign, $row, $moment);

    expect(app(DeliveryService::class)->claim($row, $message, 'crash@exemple.test', $moment))->toBeTrue();

    $row = $row->fresh();
    expect($row->status)->toBe(RecipientStatus::Sending)
        ->and($row->message_id)->toBe('crash@exemple.test')
        ->and($row->sending_started_at->equalTo($moment))->toBeTrue();

    // Le Mac redémarre : un tick par minute pendant plus d'une heure.
    $statuts = [];

    foreach (range(1, 80) as $minute) {
        tickAt($moment->addMinutes($minute)->format('Y-m-d H:i:s'));
        $statuts[$minute] = $row->fresh()->status;
    }

    expect($statuts[9])->toBe(RecipientStatus::Sending)
        ->and($statuts[12])->toBe(RecipientStatus::Uncertain)
        ->and($statuts[80])->toBe(RecipientStatus::Uncertain)
        ->and(ScriptedTransport::$calls)->toBe(0)
        ->and(sentMail())->toHaveCount(0);
});

it('n\'envoie rien d\'autre pendant qu\'une ligne est en cours d\'envoi : l\'écart minimum la compte', function () {
    $campaign = sendingCampaign();
    $stuck = dueRow($campaign, '2026-10-19 10:00:13');
    $stuck->forceFill(['status' => RecipientStatus::Sending, 'sending_started_at' => paris('2026-10-19 10:01:00')->utc()])->save();
    dueRow($campaign, '2026-10-19 10:02:00');

    expect(tickAt('2026-10-19 10:05:00')->sent())->toBe(0);
});

// --- Un seul passage à la fois ------------------------------------------------------------------

it('un second passage simultané n\'envoie rien : un seul tick à la fois', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);

    $lock = Cache::lock('slowmail:tick', 60);
    expect($lock->get())->toBeTrue();

    test()->travelTo(paris('2026-10-19 10:01:00'));
    $report = app(TickRunner::class)->run();

    expect($report->busy)->toBeTrue()
        ->and(sentMail())->toHaveCount(0)
        ->and(app(SchedulerState::class)->lastTickAt())->toBeNull();

    $lock->release();

    expect(tickAt('2026-10-19 10:01:00')->sent())->toBe(1);
});

it('rend le verrou à la fin du tick, même quand il échoue', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);
    scriptedMail(fn () => throw new RuntimeException('boom'));

    tickAt('2026-10-19 10:01:00');

    // Le verrou est libre : un autre tick peut passer.
    $lock = Cache::lock('slowmail:tick', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('deux exécutions simultanées : la ligne n\'est envoyée qu\'une fois', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    $stale = $row->fresh();
    $service = app(DeliveryService::class);
    $now = paris('2026-10-19 10:01:00');

    // Deux processus ont lu la même ligne due ; sans verrou, seule la réclamation atomique les départage.
    $first = $service->deliver($campaign, $row, $now);
    $second = $service->deliver($campaign, $stale, $now);

    expect($first->outcome)->toBe(DeliveryOutcome::Sent)
        ->and($second->outcome)->toBe(DeliveryOutcome::Lost)
        ->and(sentMail())->toHaveCount(1)
        ->and(ScriptedTransport::$calls)->toBe(1)
        ->and($row->fresh()->attempts)->toBe(1);
});

it('deux exécutions simultanées pendant qu\'un envoi est en cours : la seconde ne renvoie pas', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    $stale = $row->fresh();
    $now = paris('2026-10-19 10:01:00');

    // Le premier processus est en plein envoi quand le second arrive.
    $second = null;
    scriptedMail(function () use (&$second, $campaign, $stale, $now) {
        $second = app(DeliveryService::class)->deliver($campaign, $stale, $now);
    });

    $first = app(DeliveryService::class)->deliver($campaign, $row, $now);

    expect($first->outcome)->toBe(DeliveryOutcome::Sent)
        ->and($second->outcome)->toBe(DeliveryOutcome::Lost)
        ->and(ScriptedTransport::$calls)->toBe(1);
});

// --- Le plan complet, de l'activation à l'envoi -------------------------------------------------

it('de l\'activation à l\'envoi : le plan est tiré, puis chaque mail part à son heure, espacé des autres', function () {
    $campaign = sendingCampaign(rows: 12);
    $campaign->update(['status' => 'draft']);

    expect(app(CampaignLifecycle::class)->activate($campaign->fresh()))->toBe([]);

    $envois = [];

    for ($now = paris('2026-10-19 07:00'); $now <= paris('2026-10-19 17:45'); $now = $now->addMinute()) {
        $report = tickAt($now->format('Y-m-d H:i:s'), seed: 3);

        foreach ($report->deliveries as $delivery) {
            $envois[] = $now;
        }
    }

    expect($envois)->toHaveCount(4)
        ->and(sentMail())->toHaveCount(4)
        ->and(DispatchHistory::load($campaign, 'Europe/Paris')->total())->toBe(4);

    foreach ($envois as $i => $moment) {
        expect($moment >= paris('2026-10-19 09:30') && $moment <= paris('2026-10-19 17:30'))->toBeTrue();

        if ($i > 0) {
            expect($moment->getTimestamp() - $envois[$i - 1]->getTimestamp())->toBeGreaterThanOrEqual(25 * 60);
        }
    }
});
