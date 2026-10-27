<?php

use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use App\Enums\SuppressionReason;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Services\Sending\RecipientActions;

beforeEach(fn () => sendingEnvironment());

function actions(): RecipientActions
{
    return app(RecipientActions::class);
}

function uncertainRow(): Recipient
{
    $campaign = sendingCampaign();
    $row = Recipient::where('campaign_id', $campaign->id)->first();
    $row->forceFill([
        'status' => RecipientStatus::Uncertain,
        'sending_started_at' => paris('2026-10-19 10:01:00')->utc(),
        'message_id' => 'abc@exemple.test',
        'rendered_subject' => 'Objet',
        'rendered_body' => 'Corps',
        'last_error' => 'Envoi interrompu',
    ])->save();

    return $row->fresh();
}

// --- Réponses -----------------------------------------------------------------------------------

it('note une réponse, avec des notes', function () {
    $row = recipient(campaign(), 'marie@agence.example', RecipientStatus::Sent);

    actions()->markReply($row, ReplyStatus::Replied, 'Intéressée, rappeler en novembre');
    $row = $row->fresh();

    expect($row->reply_status)->toBe(ReplyStatus::Replied)
        ->and($row->notes)->toBe('Intéressée, rappeler en novembre')
        ->and($row->status)->toBe(RecipientStatus::Sent)
        ->and(journalOf($row->campaign))->toContain('reply');
});

it('note un rendez-vous', function () {
    $row = recipient(campaign(), 'marie@agence.example', RecipientStatus::Sent);

    actions()->markReply($row, ReplyStatus::Meeting);

    expect($row->fresh()->reply_status)->toBe(ReplyStatus::Meeting);
});

it('garde les notes quand on n\'en donne pas, et les efface avec une chaîne vide', function () {
    $row = recipient(campaign(), 'marie@agence.example', RecipientStatus::Sent);
    $row->update(['notes' => 'à garder']);

    actions()->markReply($row, ReplyStatus::Replied);
    expect($row->fresh()->notes)->toBe('à garder');

    actions()->markReply($row->fresh(), ReplyStatus::Replied, '');
    expect($row->fresh()->notes)->toBeNull();
});

it('« stop » ajoute l\'adresse à la liste de suppression et retire ses autres lignes de l\'envoi', function () {
    $a = sendingCampaign();
    $b = sendingCampaign();
    $row = Recipient::where('campaign_id', $a->id)->where('email', 'p1@domaine1.example')->sole();
    markSent($row, paris('2026-10-19 10:00'));
    $elsewhere = Recipient::where('campaign_id', $b->id)->where('email', 'p1@domaine1.example')->sole();

    actions()->markReply($row->fresh(), ReplyStatus::Stop, 'Demande à ne plus être contactée');

    expect($row->fresh()->reply_status)->toBe(ReplyStatus::Stop)
        ->and($row->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and(Suppression::where('value', 'p1@domaine1.example')->sole()->reason)->toBe(SuppressionReason::Stop)
        ->and($elsewhere->fresh()->status)->toBe(RecipientStatus::Suppressed);
});

it('« répondu » et « rendez-vous » n\'ajoutent rien à la liste de suppression', function () {
    $row = recipient(campaign(), 'marie@agence.example', RecipientStatus::Sent);

    actions()->markReply($row, ReplyStatus::Replied);
    actions()->markReply($row->fresh(), ReplyStatus::Meeting);

    expect(Suppression::count())->toBe(0);
});

// --- Envoi incertain ----------------------------------------------------------------------------

it('« C\'est parti » : l\'envoi incertain devient envoyé, à l\'heure où il avait commencé', function () {
    $row = uncertainRow();

    expect(actions()->confirmSent($row))->toBeTrue();

    $row = $row->fresh();

    expect($row->status)->toBe(RecipientStatus::Sent)
        ->and($row->sent_at->equalTo(paris('2026-10-19 10:01:00')))->toBeTrue()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBeNull()
        ->and($row->message_id)->toBe('abc@exemple.test')
        ->and(journalOf($row->campaign))->toContain('resolved');
});

it('« Pas parti » : l\'envoi incertain retourne en attente, comme neuf', function () {
    $row = uncertainRow();

    expect(actions()->notSent($row))->toBeTrue();

    $row = $row->fresh();

    expect($row->status)->toBe(RecipientStatus::Pending)
        ->and($row->planned_at)->toBeNull()
        ->and($row->sending_started_at)->toBeNull()
        ->and($row->message_id)->toBeNull()
        ->and($row->rendered_subject)->toBeNull()
        ->and($row->rendered_body)->toBeNull()
        ->and($row->last_error)->toBeNull()
        ->and($row->attempts)->toBe(0);
});

it('une ligne remise en attente est replanifiée puis envoyée une seule fois', function () {
    $row = uncertainRow();
    actions()->notSent($row);
    $campaign = $row->campaign;
    $campaign->update(['planned_for' => null]);

    for ($now = paris('2026-10-20 07:00'); $now <= paris('2026-10-20 17:45'); $now = $now->addMinute()) {
        tickAt($now->format('Y-m-d H:i:s'), seed: 5);
    }

    expect($row->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and(sentMail()->filter(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'p1@domaine1.example'))->toHaveCount(1);
});

it('une campagne dont il ne reste qu\'un envoi incertain attend le verdict : « Pas parti » le fait partir, « C\'est parti » termine la campagne', function () {
    // La seule ligne de la campagne est incertaine : la campagne ne se termine pas.
    $campaign = sendingCampaign(rows: 1);
    $row = Recipient::where('campaign_id', $campaign->id)->first();
    $row->forceFill(['status' => RecipientStatus::Uncertain, 'sending_started_at' => paris('2026-10-19 09:00')->utc()])->save();

    expect(tickAt('2026-10-19 10:00:00')->finished)->toBe([])
        ->and($campaign->fresh()->status->value)->toBe('active');

    // « C'est parti » : plus rien à faire, la campagne se termine au tick suivant.
    actions()->confirmSent($row->fresh());

    expect(tickAt('2026-10-19 10:01:00')->finished)->toHaveCount(1)
        ->and($campaign->fresh()->status->value)->toBe('finished');
});

it('« Pas parti » sur la dernière ligne d\'une campagne : elle est replanifiée et envoyée, la campagne n\'est pas déjà finie', function () {
    $campaign = sendingCampaign(rows: 1);
    $row = Recipient::where('campaign_id', $campaign->id)->first();
    $row->forceFill(['status' => RecipientStatus::Uncertain, 'sending_started_at' => paris('2026-10-19 09:00')->utc()])->save();

    tickAt('2026-10-19 10:00:00');
    actions()->notSent($row->fresh());

    $sent = 0;

    for ($now = paris('2026-10-20 07:00'); $now <= paris('2026-10-20 17:45'); $now = $now->addMinute()) {
        $sent += tickAt($now->format('Y-m-d H:i:s'), seed: 2)->sent();
    }

    expect($sent)->toBe(1)->and($row->fresh()->status)->toBe(RecipientStatus::Sent);
});

it('ne touche que les envois incertains', function () {
    foreach ([RecipientStatus::Pending, RecipientStatus::Planned, RecipientStatus::Sending, RecipientStatus::Sent, RecipientStatus::Failed, RecipientStatus::Bounced] as $i => $status) {
        $row = recipient(campaign(), "x{$i}@agence.example", $status);

        expect(actions()->confirmSent($row))->toBeFalse()
            ->and(actions()->notSent($row))->toBeFalse()
            ->and($row->fresh()->status)->toBe($status);
    }
});

it('tranche un envoi incertain une seule fois', function () {
    $row = uncertainRow();

    expect(actions()->confirmSent($row))->toBeTrue()
        ->and(actions()->confirmSent($row->fresh()))->toBeFalse()
        ->and(actions()->notSent($row->fresh()))->toBeFalse()
        ->and($row->fresh()->status)->toBe(RecipientStatus::Sent);
});
