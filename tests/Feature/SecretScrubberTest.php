<?php

use App\Support\SecretScrubber;

beforeEach(function () {
    config([
        'mail.mailers.smtp.username' => 'prospection@exemple.test',
        'mail.mailers.smtp.password' => 'S3cr&t Pass/word',
        'mail.mailers.smtp.url' => null,
        'slowmail.password' => 'mot-de-passe-interface',
        'app.key' => 'base64:Y2xlLWRlLXRlc3QtMTIzNDU2Nzg5MA==',
    ]);
});

it('masque l\'identifiant et le mot de passe SMTP', function () {
    $text = SecretScrubber::scrub('Failed to authenticate as "prospection@exemple.test" with "S3cr&t Pass/word"');

    expect($text)->toBe('Failed to authenticate as "***" with "***"');
});

it('masque aussi le mot de passe de l\'interface et la clé d\'application', function () {
    $text = SecretScrubber::scrub('a mot-de-passe-interface b base64:Y2xlLWRlLXRlc3QtMTIzNDU2Nzg5MA== c Y2xlLWRlLXRlc3QtMTIzNDU2Nzg5MA==');

    expect($text)->toBe('a *** b *** c ***');
});

it('masque les formes encodées dans une URL', function () {
    $text = SecretScrubber::scrub('smtps://prospection%40exemple.test:S3cr%26t%20Pass%2Fword@ssl0.exemple.test');

    expect($text)->not->toContain('S3cr')->not->toContain('prospection');
});

it('masque une URL de transport complète', function () {
    config(['mail.mailers.smtp.url' => 'smtps://u:p4ssw0rd@hote.exemple.test:465']);

    expect(SecretScrubber::scrub('Erreur avec smtps://u:p4ssw0rd@hote.exemple.test:465 !'))->toBe('Erreur avec *** !');
});

it('masque le plus long d\'abord, sans laisser de reste', function () {
    config(['mail.mailers.smtp.username' => 'abcd', 'mail.mailers.smtp.password' => 'abcdefgh']);

    expect(SecretScrubber::scrub('abcdefgh puis abcd'))->toBe('*** puis ***');
});

it('ignore les valeurs trop courtes pour être un secret', function () {
    config(['mail.mailers.smtp.username' => 'ab', 'mail.mailers.smtp.password' => null, 'slowmail.password' => '', 'app.key' => null]);

    expect(SecretScrubber::scrub('abracadabra'))->toBe('abracadabra');
});

it('laisse intact un texte sans secret', function () {
    expect(SecretScrubber::scrub('Connection could not be established with host "ssl0.exemple.test:465"'))
        ->toBe('Connection could not be established with host "ssl0.exemple.test:465"');
});
