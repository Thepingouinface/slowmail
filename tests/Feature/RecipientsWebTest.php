<?php

use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use App\Models\Suppression;

beforeEach(function () {
    sendingEnvironment();
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

// --- Tableau ----------------------------------------------------------------------------------

it('affiche les destinataires avec statut, date et réponse', function () {
    $campaign = campaign();
    $sent = recipient($campaign, 'envoye@x.example', RecipientStatus::Sent);
    $sent->forceFill(['sent_at' => paris('2026-10-20 11:30:00')->utc(), 'reply_status' => ReplyStatus::Meeting, 'notes' => 'Rappeler jeudi'])->save();
    $planned = recipient($campaign, 'prevu@x.example');
    planAt($planned, '2026-10-21 15:42:00');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSee('envoye@x.example')
        ->assertSee('Envoyé mar. 20 oct. à 11:30')
        ->assertSee('Rendez-vous')
        ->assertSee('Rappeler jeudi')
        ->assertSee('Prévu mer. 21 oct. à 15:42');
});

it('filtre par statut et par adresse', function () {
    $campaign = campaign();
    recipient($campaign, 'envoye@x.example', RecipientStatus::Sent);
    recipient($campaign, 'attente@x.example');
    recipient($campaign, 'autre-envoye@y.example', RecipientStatus::Sent);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}?status=sent&q=y.example")
        ->assertSee('autre-envoye@y.example')
        ->assertDontSee('envoye@x.example')
        ->assertDontSee('attente@x.example');

    $this->get("/campaigns/{$campaign->id}?q=ATTENTE")->assertSee('attente@x.example')->assertDontSee('envoye@x.example');
    $this->get("/campaigns/{$campaign->id}?q=introuvable")->assertSee('Aucun destinataire ne correspond.');
});

it('pagine les destinataires par 50 en gardant le filtre', function () {
    $campaign = campaign();

    foreach (range(1, 55) as $i) {
        recipient($campaign, "p{$i}@x.example");
    }

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}?status=pending")
        ->assertSee('p50@x.example')
        ->assertDontSee('p51@x.example')
        ->assertSeeHtml('status=pending&amp;page=2');

    $this->get("/campaigns/{$campaign->id}?status=pending&page=2")->assertSee('p55@x.example')->assertDontSee('p1@x.example');
});

it('affiche la raison des lignes ignorées, en échec, rebondies ou incertaines', function () {
    $campaign = campaign();

    foreach ([
        ['ignore@x.example', RecipientStatus::Skipped, 'Valeur vide sans valeur par défaut : [PRENOM].'],
        ['echec@x.example', RecipientStatus::Failed, 'Échec d\'envoi : 535 5.7.8 Authentication failed'],
        ['rebond@x.example', RecipientStatus::Bounced, 'RCPT TO refusé : 550 5.1.1 adresse inconnue'],
    ] as [$email, $status, $reason]) {
        recipient($campaign, $email, $status)->forceFill(['last_error' => $reason])->save();
    }

    $page = $this->withSession(authenticated())->get("/campaigns/{$campaign->id}");

    $page->assertShows('Valeur vide sans valeur par défaut : [PRENOM].')
        ->assertShows('Échec d\'envoi : 535 5.7.8 Authentication failed')
        ->assertShows('RCPT TO refusé : 550 5.1.1 adresse inconnue')
        ->assertSee('Ignoré')
        ->assertSee('Échec')
        ->assertSee('Rebond');
});

it('masque l\'identifiant SMTP dans la raison d\'une ligne', function () {
    config(['mail.mailers.smtp.username' => 'compte-ovh-secret']);
    $campaign = campaign();
    recipient($campaign, 'echec@x.example', RecipientStatus::Failed)->forceFill(['last_error' => 'Échec pour compte-ovh-secret : refusé'])->save();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertDontSee('compte-ovh-secret')
        ->assertShows('Échec pour *** : refusé');
});

it('montre les données du CSV d\'une ligne, sans jamais montrer le corps d\'un mail', function () {
    $campaign = campaign();
    recipient($campaign, 'ligne@x.example', RecipientStatus::Sent, ['PRENOM' => 'Camille', 'STRUCTURE' => 'Atelier Lune'])
        ->forceFill(['rendered_subject' => 'Un objet', 'rendered_body' => 'CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE'])->save();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSee('Camille')
        ->assertSee('Atelier Lune')
        ->assertDontSee('CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE');
});

// --- Réponses ---------------------------------------------------------------------------------

it('note une réponse avec ses notes', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'reponse@x.example', RecipientStatus::Sent);

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/recipients/{$row->id}/reply", ['status' => 'replied', 'notes' => "Intéressé\r\npar l'offre"])
        ->assertRedirect("/campaigns/{$campaign->id}");

    expect($row->fresh()->reply_status)->toBe(ReplyStatus::Replied)
        ->and($row->fresh()->notes)->toBe("Intéressé\npar l'offre")
        ->and(journalOf($campaign))->toContain('reply')
        ->and(Suppression::count())->toBe(0);

    $this->get("/campaigns/{$campaign->id}")->assertShows('reponse@x.example : marqué « Répondu ».')->assertSee('Répondu');
});

it('note un rendez-vous', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'rdv@x.example', RecipientStatus::Sent);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/reply", ['status' => 'meeting', 'notes' => '']);

    expect($row->fresh()->reply_status)->toBe(ReplyStatus::Meeting)->and($row->fresh()->notes)->toBeNull();
});

it('ajoute l\'adresse à la liste de suppression avec « stop » et retire ses lignes en attente de toutes les campagnes', function () {
    $campaign = campaign('Une');
    $other = campaign('Deux');
    $row = recipient($campaign, 'stop@x.example', RecipientStatus::Sent);
    $third = campaign('Trois');
    $waiting = recipient($other, 'stop@x.example');
    $planned = planAt(recipient($third, 'stop@x.example'), '2026-10-21 15:00:00');

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/reply", ['status' => 'stop', 'notes' => 'Ne plus écrire']);

    $suppression = Suppression::sole();
    expect($row->fresh()->reply_status)->toBe(ReplyStatus::Stop)
        ->and($suppression->value)->toBe('stop@x.example')
        ->and($suppression->reason->value)->toBe('stop')
        ->and($waiting->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($planned->fresh()->status)->toBe(RecipientStatus::Suppressed);

    $this->get("/campaigns/{$campaign->id}")->assertShows('L\'adresse est dans la liste de suppression : elle ne recevra plus rien.');
    $this->get('/suppressions')->assertSee('stop@x.example');
});

it('efface les notes quand la zone est vidée', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'notes@x.example', RecipientStatus::Sent);
    $row->forceFill(['notes' => 'À effacer'])->save();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/reply", ['status' => 'replied', 'notes' => '']);

    expect($row->fresh()->notes)->toBeNull()->and($row->fresh()->reply_status)->toBe(ReplyStatus::Replied);
});

it('préremplit la zone de notes : un clic sur « Répondu » ne les efface pas', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'notes@x.example', RecipientStatus::Sent);
    $row->forceFill(['notes' => 'À garder'])->save();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")->assertSeeHtml('>À garder</textarea>');
});

it('refuse un statut de réponse inconnu et des notes trop longues, en français', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'x@x.example', RecipientStatus::Sent);
    $url = "/campaigns/{$campaign->id}/recipients/{$row->id}/reply";

    $this->withSession(authenticated())->post($url, ['status' => 'perdu'])
        ->assertSessionHasErrors(['status' => 'La valeur choisie pour le champ statut n\'est pas valable.']);

    $this->post($url, ['status' => 'replied', 'notes' => str_repeat('a', 2001)])
        ->assertSessionHasErrors(['notes' => 'Le champ notes ne doit pas dépasser 2000 caractères.']);

    $this->post($url, [])->assertSessionHasErrors(['status' => 'Le champ statut est obligatoire.']);

    expect($row->fresh()->reply_status)->toBeNull();
});

it('affiche les notes en texte, sans interpréter de HTML', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'xss@x.example', RecipientStatus::Sent);
    $row->forceFill(['notes' => '<script>alert(1)</script>'])->save();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

// --- Envois incertains ------------------------------------------------------------------------

it('montre le bandeau « à vérifier dans vos envoyés » avec les deux boutons', function () {
    $campaign = campaign();
    $row = uncertainSend($campaign);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSeeHtml('data-uncertain-panel')
        ->assertSee('À vérifier dans vos envoyés (1)')
        ->assertSee('incertain@exemple.test')
        ->assertShows('Envoi commencé le lun. 19 oct. à 10:00')
        ->assertShows('objet : « Objet du mail incertain »')
        ->assertSee('abc123def456@exemple.test')
        ->assertSee('Envoi interrompu sans réponse du serveur')
        ->assertSeeHtml('action="'.route('recipients.confirm-sent', [$campaign, $row]).'"')
        ->assertSeeHtml('action="'.route('recipients.not-sent', [$campaign, $row]).'"')
        ->assertSee('C\'est parti', false)
        ->assertSee('Pas parti')
        ->assertDontSee('CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE');
});

it('ne montre pas le bandeau quand rien n\'est incertain', function () {
    $campaign = campaign();
    recipient($campaign, 'ok@x.example', RecipientStatus::Sent);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")->assertDontSeeHtml('data-uncertain-panel');
});

it('« C\'est parti » note la ligne comme envoyée', function () {
    $campaign = campaign();
    $row = uncertainSend($campaign);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/confirm-sent")->assertRedirect("/campaigns/{$campaign->id}");

    expect($row->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and($row->fresh()->sent_at->utc()->format('Y-m-d H:i:s'))->toBe(paris('2026-10-19 10:00:13')->utc()->format('Y-m-d H:i:s'))
        ->and(journalOf($campaign))->toContain('resolved');

    $this->get("/campaigns/{$campaign->id}")->assertDontSeeHtml('data-uncertain-panel')->assertShows('incertain@exemple.test : noté comme envoyé.');
});

it('« Pas parti » remet la ligne en attente, sans rendu ni identifiant', function () {
    $campaign = campaign();
    $row = uncertainSend($campaign);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/not-sent")->assertRedirect();

    $row = $row->fresh();
    expect($row->status)->toBe(RecipientStatus::Pending)
        ->and($row->message_id)->toBeNull()
        ->and($row->rendered_body)->toBeNull()
        ->and($row->planned_at)->toBeNull();

    $this->get("/campaigns/{$campaign->id}")->assertShows('incertain@exemple.test : remis en attente, il partira à un prochain créneau.');
});

it('demande confirmation avant de trancher un envoi incertain', function () {
    $campaign = campaign();
    uncertainSend($campaign);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSeeHtml('data-confirm="Le mail à incertain@exemple.test est bien dans vos envoyés ?')
        ->assertSeeHtml('data-confirm="Vous avez vérifié que le mail à incertain@exemple.test n\'est PAS dans vos envoyés ?');
});

it('ne tranche pas deux fois la même ligne', function () {
    $campaign = campaign();
    $row = uncertainSend($campaign);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/confirm-sent");
    $this->post("/campaigns/{$campaign->id}/recipients/{$row->id}/not-sent");

    expect($row->fresh()->status)->toBe(RecipientStatus::Sent);
    $this->get("/campaigns/{$campaign->id}")->assertShows('incertain@exemple.test n\'est plus à vérifier.');
});

it('ne touche pas une ligne qui n\'est pas incertaine', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'attente@x.example');

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/recipients/{$row->id}/confirm-sent");

    expect($row->fresh()->status)->toBe(RecipientStatus::Pending);
});

it('plafonne la liste détaillée des lignes incertaines et renvoie vers le filtre', function () {
    $campaign = campaign();

    foreach (range(1, 22) as $i) {
        uncertainSend($campaign, "inc{$i}@exemple.test");
    }

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSee('À vérifier dans vos envoyés (22)')
        ->assertShows('… et 2 autre(s) : voir toutes les lignes à vérifier.');
});

it('annonce les lignes incertaines sur les autres onglets de la campagne', function () {
    $campaign = campaign();
    uncertainSend($campaign);

    foreach (['/template', '/settings', '/journal'] as $page) {
        $this->withSession(authenticated())->get("/campaigns/{$campaign->id}{$page}")
            ->assertSeeHtml('data-uncertain')
            ->assertShows('1 envoi(s) à vérifier dans vos envoyés.');
    }
});
