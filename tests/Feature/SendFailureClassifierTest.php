<?php

use App\Enums\SendFailureKind;
use App\Services\Sending\SendFailure;
use App\Services\Sending\SendFailureClassifier;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerInvalidArgument;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

function classified(Throwable $e): SendFailure
{
    return app(SendFailureClassifier::class)->classify($e);
}

// --- Le serveur a répondu ----------------------------------------------------------------------

it('reconnaît une adresse refusée par le serveur : réponse 5xx à RCPT TO', function () {
    $failure = classified(smtpReply(550, '5.1.1 <p1@domaine1.example>: Recipient address rejected: User unknown', 'RCPT TO:<p1@domaine1.example>'));

    expect($failure->kind)->toBe(SendFailureKind::RecipientRejected)
        ->and($failure->code)->toBe(550)
        ->and($failure->countsForBreaker())->toBeFalse();
});

it('ne prend pas pour un rebond un refus de l\'expéditeur ou de la politique', function () {
    foreach ([
        smtpReply(550, '5.7.1 Sender address rejected: not owned by user', 'MAIL FROM:<moi@exemple.test>'),
        smtpReply(554, '5.7.1 Relay access denied', 'MAIL FROM:<moi@exemple.test>'),
        smtpReply(554, '5.3.0 Transaction failed', 'DATA'),
        smtpReply(552, '5.2.3 Message size exceeds fixed limit', '.'),
        smtpReply(554, '5.7.1 Message rejected as spam', '.'),
    ] as $e) {
        $failure = classified($e);

        expect($failure->kind)->toBe(SendFailureKind::Permanent)->and($failure->countsForBreaker())->toBeTrue();
    }
});

it('reconnaît une réponse « plus tard » (4xx), à n\'importe quelle étape', function () {
    foreach ([
        smtpReply(451, '4.7.1 Greylisted, please try again later', 'RCPT TO:<p1@domaine1.example>'),
        smtpReply(421, '4.4.2 Timeout', 'DATA'),
        smtpReply(451, '4.3.0 Mail system temporarily unavailable', '.'),
    ] as $e) {
        $failure = classified($e);

        expect($failure->kind)->toBe(SendFailureKind::Temporary)->and($failure->countsForBreaker())->toBeTrue();
    }
});

it('reconnaît un refus d\'authentification, définitif, et masque l\'identifiant', function () {
    config(['mail.mailers.smtp.username' => 'prospection@exemple.test', 'mail.mailers.smtp.password' => 'S3cretPassw0rd']);

    $e = new TransportException('Failed to authenticate on SMTP server with username "prospection@exemple.test" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".".', 535);
    $e->appendDebug(smtpTranscript('AUTH LOGIN', '535 5.7.8 Authentication failed'));

    $failure = classified($e);

    expect($failure->kind)->toBe(SendFailureKind::Permanent)
        ->and($failure->message)->not->toContain('prospection@exemple.test')->toContain('***')
        ->and($failure->countsForBreaker())->toBeTrue();
});

it('reconnaît un refus d\'authentification temporaire', function () {
    $e = new TransportException('Failed to authenticate on SMTP server.', 454);

    expect(classified($e)->kind)->toBe(SendFailureKind::Temporary);
});

it('classe un refus d\'adresse sans journal de conversation d\'après l\'état 5.1.x ou les mots', function () {
    foreach ([
        [550, '550 5.1.1 No such user here'],
        [553, '553 mailbox unavailable'],
        [550, '550 Recipient address rejected'],
    ] as [$code, $reply]) {
        $e = new UnexpectedResponseException("Expected response code \"250/251/252\" but got code \"{$code}\", with message \"{$reply}\".", $code);

        expect(classified($e)->kind)->toBe(SendFailureKind::RecipientRejected, $reply);
    }

    $autre = new UnexpectedResponseException('Expected response code "250" but got code "554", with message "554 Transaction failed".', 554);

    expect(classified($autre)->kind)->toBe(SendFailureKind::Permanent);
});

// --- Pas de réponse du serveur ------------------------------------------------------------------

it('reconnaît l\'absence de réseau : rien n\'est parti, on réessaiera', function () {
    foreach ([
        connectionFailure(),
        connectionFailure('Connection to "ssl0.ovh.net:465" timed out.'),
        connectionFailure('Connection to "ssl0.ovh.net:465" has been closed unexpectedly.', smtpTranscript('MAIL FROM:<moi@exemple.test>')),
        connectionFailure('Unable to write bytes on the wire.', smtpTranscript('RCPT TO:<p1@domaine1.example>')),
    ] as $e) {
        $failure = classified($e);

        expect($failure->kind)->toBe(SendFailureKind::Network)->and($failure->countsForBreaker())->toBeFalse();
    }
});

it('considère comme sans risque une coupure en plein corps du message, avant le « . » final', function () {
    // Le serveur n'a jamais reçu la fin du message : il ne peut pas l'avoir accepté.
    $e = connectionFailure('Unable to write bytes on the wire.', smtpTranscript('DATA', '354 go ahead'));

    expect(classified($e)->kind)->toBe(SendFailureKind::Network);
});

it('considère comme incertain un message dont le « . » final est parti sans réponse', function () {
    foreach ([
        connectionFailure('Connection to "ssl0.ovh.net:465" timed out.', smtpTranscript('.')),
        connectionFailure('Connection to "ssl0.ovh.net:465" has been closed unexpectedly.', smtpTranscript('.')),
    ] as $e) {
        $failure = classified($e);

        expect($failure->kind)->toBe(SendFailureKind::Uncertain)->and($failure->countsForBreaker())->toBeFalse();
    }
});

it('considère comme incertaine une réponse vide après le « . » final', function () {
    $e = new UnexpectedResponseException('Expected response code "250" but got empty code.', 0);
    $e->appendDebug(smtpTranscript('.'));

    expect(classified($e)->kind)->toBe(SendFailureKind::Uncertain);
});

it('considère comme incertaine toute erreur que l\'on ne comprend pas pendant l\'envoi', function () {
    expect(classified(new RuntimeException('Quelque chose d\'inattendu'))->kind)->toBe(SendFailureKind::Uncertain)
        ->and(classified(new Error('Erreur fatale'))->kind)->toBe(SendFailureKind::Uncertain);
});

// --- Adresse impossible à envoyer ---------------------------------------------------------------

it('reconnaît une adresse impossible à envoyer, hors disjoncteur', function () {
    foreach ([new RfcComplianceException('Email "x" does not comply with addr-spec of RFC 2822.'), new MailerInvalidArgument('Invalid addresses: non-ASCII characters not supported in local-part of email.')] as $e) {
        $failure = classified($e);

        expect($failure->kind)->toBe(SendFailureKind::InvalidAddress)->and($failure->countsForBreaker())->toBeFalse();
    }
});

// --- Le message d'erreur ------------------------------------------------------------------------

it('garde un message sur une ligne, tronqué, sans identifiants', function () {
    config(['mail.mailers.smtp.password' => 'S3cretPassw0rd']);

    $long = "Ligne un\n\n   ligne deux S3cretPassw0rd ".str_repeat('x', 500);

    $failure = classified(connectionFailure($long));

    expect($failure->message)->not->toContain("\n")->not->toContain('S3cretPassw0rd')
        ->and(mb_strlen($failure->message))->toBeLessThanOrEqual(300);
});
