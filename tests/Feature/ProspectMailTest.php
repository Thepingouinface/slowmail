<?php

use App\Mail\ProspectMail;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

function sendProspectMail(ProspectMail $mail, string $to = 'marie@lumiere.example'): Email
{
    config(['mail.from.address' => 'moi@exemple.test', 'mail.from.name' => 'Matthieu']);

    Mail::to($to)->send($mail);

    return Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it('envoie du texte brut, sans HTML ni pièce jointe', function () {
    $email = sendProspectMail(new ProspectMail('Objet', 'Bonjour'));

    expect($email->getTextBody())->not->toBeNull()
        ->and($email->getHtmlBody())->toBeNull()
        ->and($email->getAttachments())->toBe([]);
});

it('envoie exactement le corps rendu, sans ligne ajoutée ni caractère échappé', function () {
    $body = "Bonjour Marie,\n\nÀ propos de <b>l'Agence</b> & Cie : \"oui\".\n\nRépondez STOP.";

    expect(sendProspectMail(new ProspectMail('Objet', $body))->getTextBody())->toBe($body);
});

it('pose l\'objet et le destinataire', function () {
    $email = sendProspectMail(new ProspectMail('Pour l\'Agence Lumière', 'Bonjour'), 'marie@lumiere.example');

    expect($email->getSubject())->toBe('Pour l\'Agence Lumière')
        ->and($email->getTo()[0]->getAddress())->toBe('marie@lumiere.example');
});

it('expédie depuis l\'adresse du .env', function () {
    $from = sendProspectMail(new ProspectMail('Objet', 'Bonjour'))->getFrom()[0];

    expect($from->getAddress())->toBe('moi@exemple.test')->and($from->getName())->toBe('Matthieu');
});

it('répond à l\'adresse d\'expédition par défaut, ou à SLOWMAIL_REPLY_TO', function () {
    expect(sendProspectMail(new ProspectMail('Objet', 'Bonjour'))->getReplyTo()[0]->getAddress())->toBe('moi@exemple.test');

    config(['slowmail.reply_to' => 'reponses@exemple.test']);

    expect(sendProspectMail(new ProspectMail('Objet', 'Bonjour'))->getReplyTo()[0]->getAddress())->toBe('reponses@exemple.test');
});

it('ajoute List-Unsubscribe vers l\'adresse d\'expédition', function () {
    $email = sendProspectMail(new ProspectMail('Objet', 'Bonjour'));

    expect($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toBe('<mailto:moi@exemple.test>');
});

it('pose le Message-ID fourni, pour pouvoir le stocker avant l\'envoi', function () {
    $email = sendProspectMail(new ProspectMail('Objet', 'Bonjour', 'abc123@exemple.test'));

    expect($email->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<abc123@exemple.test>');
});

it('laisse Symfony générer le Message-ID quand aucun n\'est fourni', function () {
    $email = sendProspectMail(new ProspectMail('Objet', 'Bonjour'));

    // Symfony pose l'en-tête au moment de préparer le message, pas avant.
    expect($email->getPreparedHeaders()->has('Message-ID'))->toBeTrue();
});
