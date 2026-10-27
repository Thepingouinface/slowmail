<?php

use Illuminate\Support\Facades\Mail;

it('n\'envoie aucun vrai mail pendant les tests', function () {
    expect(config('mail.default'))->toBe('array');
    expect(Mail::mailer()->getSymfonyTransport()::class)->not->toContain('Smtp');
});

it('garde le kill switch fermé par défaut', function () {
    expect(config('slowmail.enabled'))->toBeFalse();
});
