<?php

use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Import\CsvImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Europe/Paris'));
    config(['slowmail.enabled' => true, 'mail.from.address' => 'moi@exemple.test']);
});

function templateCampaign(): Campaign
{
    $campaign = campaign('Agences Lyon');
    $campaign->update([
        'subject' => 'Pour [STRUCTURE|votre structure]',
        'body' => "Bonjour [PRENOM],\n\nNous sommes le [DATE].\n\nPour ne plus recevoir de mails, répondez STOP.",
        'columns' => ['PRENOM', 'STRUCTURE'],
    ]);

    recipient($campaign, 'marie@lumiere.example', data: ['PRENOM' => 'Marie', 'STRUCTURE' => 'Agence Lumière']);
    recipient($campaign, 'paul@nord.example', data: ['PRENOM' => 'Paul', 'STRUCTURE' => '']);

    return $campaign;
}

function templatePage(Campaign $campaign, array $query = []): TestResponse
{
    return test()->withSession(authenticated())->get(route('campaigns.template', ['campaign' => $campaign, ...$query]));
}

// --- Accès et navigation ---------------------------------------------------------------------

it('exige le mot de passe', function () {
    $campaign = templateCampaign();

    $this->get(route('campaigns.template', $campaign))->assertRedirect('/login');
    $this->put(route('campaigns.template.update', $campaign), ['subject' => 'x'])->assertRedirect('/login');
    $this->post(route('campaigns.template.test', $campaign), ['recipient_id' => 1])->assertRedirect('/login');
});

it('relie l\'onglet Modèle et l\'onglet Destinataires', function () {
    $campaign = templateCampaign();

    $this->withSession(authenticated())->get(route('campaigns.show', $campaign))
        ->assertOk()->assertSee(route('campaigns.template', $campaign), false);

    templatePage($campaign)->assertOk()->assertSee(route('campaigns.show', $campaign), false);
});

it('affiche une campagne toute neuve sans erreur', function () {
    templatePage(campaign())
        ->assertOk()
        ->assertSee('Aucune colonne pour')
        ->assertSee('Aucun destinataire')
        ->assertSee('les contrôles s\'affichent ici', false);
});

it('invite à écrire le modèle, plutôt que de crier à l\'erreur, quand il est encore vide', function () {
    $campaign = campaign();
    recipient($campaign, 'marie@lumiere.example', data: ['PRENOM' => 'Marie']);

    templatePage($campaign)
        ->assertOk()
        ->assertSee('Écrivez et enregistrez le modèle pour voir', false)
        ->assertDontSee('Corrigez les erreurs')
        ->assertSee('Le modèle est vide : écrivez-le', false);
});

it('refuse le test d\'un modèle vide avec un message dédié', function () {
    Mail::fake();
    $campaign = campaign();
    $marie = recipient($campaign, 'marie@lumiere.example');

    sendTest($campaign, $marie);

    Mail::assertNothingSent();
    $this->get(route('campaigns.template', ['campaign' => $campaign, 'recipient' => $marie->id]))
        ->assertSee('Le modèle est vide : écrivez-le et enregistrez-le avant d\'envoyer un test.');
});

// --- Enregistrement --------------------------------------------------------------------------

it('enregistre l\'objet et le corps', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->put(route('campaigns.template.update', $campaign), ['subject' => 'Bonjour [PRENOM]', 'body' => "Texte\nSTOP"])
        ->assertRedirect(route('campaigns.template', $campaign))
        ->assertSessionHas('status', 'Modèle enregistré.');

    expect($campaign->fresh())->subject->toBe('Bonjour [PRENOM]')->body->toBe("Texte\nSTOP");
});

it('range les fins de ligne Windows en « \n »', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->put(route('campaigns.template.update', $campaign), ['subject' => 'x', 'body' => "a\r\nb\rc"]);

    expect($campaign->fresh()->body)->toBe("a\nb\nc");
});

it('garde la ligne d\'aperçu choisie après l\'enregistrement', function () {
    $campaign = templateCampaign();
    $paul = $campaign->recipients()->where('email', 'paul@nord.example')->first();

    $this->withSession(authenticated())
        ->put(route('campaigns.template.update', $campaign), ['subject' => 'x', 'body' => 'y', 'recipient' => $paul->id])
        ->assertRedirect(route('campaigns.template', ['campaign' => $campaign, 'recipient' => $paul->id]));
});

it('vide l\'objet et le corps quand on les efface', function () {
    $campaign = templateCampaign();

    $this->withSession(authenticated())->put(route('campaigns.template.update', $campaign), ['subject' => '', 'body' => '']);

    expect($campaign->fresh())->subject->toBeNull()->body->toBeNull();
});

it('refuse un objet ou un corps trop long', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->put(route('campaigns.template.update', $campaign), ['subject' => str_repeat('a', 301), 'body' => str_repeat('b', 20001)])
        ->assertSessionHasErrors(['subject', 'body']);

    expect($campaign->fresh()->subject)->toBeNull();
});

it('enregistre un modèle incorrect et affiche les erreurs, sans le refuser', function () {
    $campaign = templateCampaign();

    $this->withSession(authenticated())
        ->put(route('campaigns.template.update', $campaign), ['subject' => 'x', 'body' => 'Bonjour [PRENOM']);

    expect($campaign->fresh()->body)->toBe('Bonjour [PRENOM');

    templatePage($campaign)->assertSee('crochet ouvert jamais fermé');
});

// --- Contrôles -------------------------------------------------------------------------------

it('confirme qu\'un modèle valide peut activer la campagne', function () {
    templatePage(templateCampaign())->assertSee('Le modèle est valide');
});

it('affiche les variables inconnues avec les colonnes disponibles', function () {
    $campaign = templateCampaign();
    $campaign->update(['body' => "Bonjour [VILLE]\nSTOP"]);

    templatePage($campaign)
        ->assertSee('Variables inconnues : [VILLE]')
        ->assertSee('Colonnes disponibles : [PRENOM], [STRUCTURE], [DATE], [EMAIL]');
});

it('affiche le blocage quand la mention de désinscription manque', function () {
    $campaign = templateCampaign();
    $campaign->update(['body' => 'Bonjour [PRENOM]']);

    templatePage($campaign)->assertSee('mention de désinscription')->assertDontSee('Le modèle est valide');
});

it('liste les variables disponibles', function () {
    templatePage(templateCampaign())
        ->assertSeeInOrder(['[PRENOM]', '[STRUCTURE]', '[DATE]', '[EMAIL]'], false);
});

// --- Aperçu ----------------------------------------------------------------------------------

it('montre par défaut l\'aperçu de la première ligne', function () {
    templatePage(templateCampaign())
        ->assertSee('Pour Agence Lumière')
        ->assertSee('Bonjour Marie,')
        ->assertSee('Nous sommes le 21 octobre 2026.');
});

it('montre l\'aperçu de la ligne choisie, avec la valeur par défaut', function () {
    $campaign = templateCampaign();
    $paul = $campaign->recipients()->where('email', 'paul@nord.example')->first();

    templatePage($campaign, ['recipient' => $paul->id])
        ->assertSee('Pour votre structure')
        ->assertSee('Bonjour Paul,')
        ->assertDontSee('Bonjour Marie,');
});

it('ne montre jamais une ligne d\'une autre campagne', function () {
    $campaign = templateCampaign();
    $foreign = recipient(campaign('Autre'), 'secret@ailleurs.example', data: ['PRENOM' => 'Intrus']);

    templatePage($campaign, ['recipient' => $foreign->id])
        ->assertSee('Bonjour Marie,')
        ->assertDontSee('Intrus')
        ->assertDontSee('secret@ailleurs.example');
});

it('prévient quand la ligne serait ignorée', function () {
    $campaign = templateCampaign();
    $campaign->update(['subject' => 'Pour [STRUCTURE]']);
    $paul = $campaign->recipients()->where('email', 'paul@nord.example')->first();

    templatePage($campaign, ['recipient' => $paul->id])
        ->assertSee('Cette ligne serait ignorée')
        ->assertSee('[STRUCTURE]');
});

it('refuse l\'aperçu quand le modèle contient des erreurs', function () {
    $campaign = templateCampaign();
    $campaign->update(['body' => 'Bonjour [PRENOM']);

    templatePage($campaign)->assertSee('Corrigez les erreurs du modèle pour voir')->assertDontSee('Bonjour Marie');
});

it('filtre les lignes par adresse et sélectionne le premier résultat', function () {
    $campaign = templateCampaign();

    templatePage($campaign, ['q' => 'nord'])
        ->assertSee('paul@nord.example')
        ->assertDontSee('marie@lumiere.example')
        ->assertSee('Bonjour Paul,');
});

it('garde la ligne choisie dans la liste même si le filtre ne la retient pas', function () {
    $campaign = templateCampaign();
    $marie = $campaign->recipients()->where('email', 'marie@lumiere.example')->first();

    templatePage($campaign, ['q' => 'nord', 'recipient' => $marie->id])
        ->assertSee('marie@lumiere.example')
        ->assertSee('Bonjour Marie,');
});

it('n\'interprète pas le HTML des données', function () {
    $campaign = templateCampaign();
    $campaign->recipients()->first()->update(['data' => ['PRENOM' => '<script>alert(1)</script>', 'STRUCTURE' => 'X']]);

    templatePage($campaign)->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('parcourt tout : import d\'un CSV, puis aperçu avec ses données', function () {
    $campaign = campaign('Import réel');
    $importer = app(CsvImporter::class);
    $importer->commit($campaign, $importer->analyze($campaign, csvFile("\xEF\xBB\xBFEMAIL;PRENOM;STRUCTURE\nmarie@lumiere.example;Marie;Agence Lumière\nsophie@bernard.example;Sophie;\n")));

    $campaign->update([
        'subject' => 'Pour [STRUCTURE|votre structure]',
        'body' => "Bonjour [PRENOM], le [DATE]\nSTOP",
    ]);

    templatePage($campaign)->assertSee('[PRENOM]')->assertSee('Pour Agence Lumière')->assertSee('Bonjour Marie, le 21 octobre 2026');
    templatePage($campaign, ['q' => 'bernard'])->assertSee('Pour votre structure');
});

// --- Mail de test ----------------------------------------------------------------------------

function sendTest(Campaign $campaign, Recipient $recipient): TestResponse
{
    return test()->withSession(authenticated())->post(route('campaigns.template.test', $campaign), ['recipient_id' => $recipient->id]);
}

it('envoie un vrai mail de test à l\'adresse d\'expédition, avec les données de la ligne', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $marie = $campaign->recipients()->where('email', 'marie@lumiere.example')->first();

    sendTest($campaign, $marie)
        ->assertRedirect(route('campaigns.template', ['campaign' => $campaign, 'recipient' => $marie->id]))
        ->assertSessionHas('status', 'Mail de test envoyé à moi@exemple.test.');

    Mail::assertSent(ProspectMail::class, 1);
    Mail::assertSent(ProspectMail::class, fn (ProspectMail $mail) => $mail->hasTo('moi@exemple.test')
        && ! $mail->hasTo('marie@lumiere.example')
        && $mail->mailSubject === 'Pour Agence Lumière'
        && $mail->mailBody === "Bonjour Marie,\n\nNous sommes le 21 octobre 2026.\n\nPour ne plus recevoir de mails, répondez STOP.");
});

it('ne touche pas à la ligne utilisée pour le test', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $marie = $campaign->recipients()->where('email', 'marie@lumiere.example')->first();
    $before = $marie->fresh()->getAttributes();

    sendTest($campaign, $marie);

    expect($marie->fresh()->getAttributes())->toBe($before)
        ->and($marie->fresh())
        ->status->value->toBe('pending')
        ->attempts->toBe(0)
        ->sent_at->toBeNull()
        ->sending_started_at->toBeNull()
        ->planned_at->toBeNull()
        ->message_id->toBeNull()
        ->rendered_subject->toBeNull()
        ->rendered_body->toBeNull();
});

it('garde une trace du test dans le journal', function () {
    Mail::fake();
    $campaign = templateCampaign();

    sendTest($campaign, $campaign->recipients()->first());

    expect(SendLog::where('event', 'test')->sole())
        ->campaign_id->toBe($campaign->id)
        ->recipient_id->toBeNull()
        ->detail->toContain('moi@exemple.test');
});

it('ne change pas le statut même quand la ligne est déjà envoyée', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $marie = $campaign->recipients()->first();
    $marie->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => 1]);

    sendTest($campaign, $marie);

    expect($marie->fresh())->status->value->toBe('sent')->attempts->toBe(1);
});

it('refuse le test quand le kill switch est coupé', function () {
    Mail::fake();
    config(['slowmail.enabled' => false]);
    $campaign = templateCampaign();

    sendTest($campaign, $campaign->recipients()->first())
        ->assertSessionHasErrors(['test' => 'Envois désactivés (SLOWMAIL_ENABLED=false) : le mail de test ne peut pas partir.']);

    Mail::assertNothingSent();
    expect(SendLog::count())->toBe(0);
});

it('refuse le test sans adresse d\'expédition valide', function () {
    Mail::fake();
    $campaign = templateCampaign();

    foreach ([null, '', 'pas-une-adresse'] as $address) {
        config(['mail.from.address' => $address]);

        sendTest($campaign, $campaign->recipients()->first())->assertSessionHasErrors('test');
    }

    Mail::assertNothingSent();
});

it('refuse le test quand le modèle contient des erreurs', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $campaign->update(['body' => 'Bonjour [VILLE]']);

    sendTest($campaign, $campaign->recipients()->first())
        ->assertSessionHasErrors(['test' => 'Le modèle contient des erreurs : corrigez-le avant d\'envoyer un test.']);

    Mail::assertNothingSent();
});

it('refuse le test pour une ligne qui ne partirait pas', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $campaign->update(['subject' => 'Pour [STRUCTURE]']);
    $paul = $campaign->recipients()->where('email', 'paul@nord.example')->first();

    sendTest($campaign, $paul)
        ->assertSessionHasErrors(['test' => 'Cette ligne ne partirait pas : valeur vide pour [STRUCTURE], sans valeur par défaut. Choisissez une autre ligne.']);

    Mail::assertNothingSent();
});

it('envoie un test même sans mention de désinscription : seule l\'activation l\'exige', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $campaign->update(['body' => 'Bonjour [PRENOM]']);

    sendTest($campaign, $campaign->recipients()->first())->assertSessionHasNoErrors();

    Mail::assertSent(ProspectMail::class, 1);
});

it('refuse une ligne d\'une autre campagne', function () {
    Mail::fake();
    $campaign = templateCampaign();
    $foreign = recipient(campaign('Autre'), 'secret@ailleurs.example');

    sendTest($campaign, $foreign)->assertNotFound();

    Mail::assertNothingSent();
});

it('exige une ligne', function () {
    $campaign = templateCampaign();

    $this->withSession(authenticated())->post(route('campaigns.template.test', $campaign), [])->assertSessionHasErrors('recipient_id');
});

it('désactive le bouton et explique pourquoi quand le test est impossible', function () {
    config(['slowmail.enabled' => false]);

    templatePage(templateCampaign())
        ->assertSee('le mail de test ne peut pas partir')
        ->assertSee('disabled', false);
});

it('affiche l\'échec du test sans jamais montrer les identifiants SMTP', function () {
    config([
        'mail.mailers.smtp.username' => 'prospection@exemple.test',
        'mail.mailers.smtp.password' => 'S3cretPassw0rd',
        'mail.default' => 'failing',
        'mail.mailers.failing' => ['transport' => 'failing'],
    ]);

    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Failed to authenticate as "prospection@exemple.test" with password "S3cretPassw0rd".');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });

    $campaign = templateCampaign();
    $marie = $campaign->recipients()->first();
    $before = $marie->fresh()->getAttributes();

    sendTest($campaign, $marie);

    // L'erreur est affichée sur la page suivante : c'est là qu'elle ne doit rien révéler.
    // Ni assertSessionHasErrors() ni withSession() avant cette requête : ils effacent l'erreur flashée.
    $this->get(route('campaigns.template', ['campaign' => $campaign, 'recipient' => $marie->id]))
        ->assertSee('L\'envoi a échoué')
        ->assertSee('***')
        ->assertDontSee('S3cretPassw0rd')
        ->assertDontSee('prospection@exemple.test');

    expect(SendLog::where('event', 'test_failed')->sole()->detail)->not->toContain('S3cretPassw0rd')->not->toContain('prospection@exemple.test')
        ->and($marie->fresh()->getAttributes())->toBe($before);
});
