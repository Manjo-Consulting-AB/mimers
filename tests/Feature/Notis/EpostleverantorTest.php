<?php

/*
 * Issue 38a · Byt e-postleverantör till Mailgun — transporten, se
 * [[ADR-0010 Notisarkitektur]] § Motivering (uppföljning 2026-09-05).
 *
 * Postmark-paketet, mailern och nycklarna byts mot Mailguns. Testerna läser
 * konfiguration och filinnehåll — de skickar inga mejl, och sviten kör
 * array-mailern (phpunit.xml) oavsett vad .env.example säger. Webhooken är
 * 38b och rörs inte här.
 */

it('har mailgun som mailer och ingen postmark-mailer kvar', function () {
    expect(config('mail.mailers.mailgun.transport'))->toBe('mailgun');
    expect(config('mail.mailers.postmark'))->toBeNull();
});

it('har en roundrobin utan transport vars paket inte är installerat', function () {
    $roundrobin = config('mail.mailers.roundrobin.mailers');

    expect($roundrobin)->toContain('mailgun');
    expect($roundrobin)->not->toContain('postmark');
});

it('har services.mailgun med domän, hemlighet, endpoint och scheme', function () {
    expect(config('services.mailgun'))->toHaveKeys([
        'domain',
        'secret',
        'endpoint',
        'scheme',
    ]);
    expect(config('services.postmark'))->toBeNull();
});

it('har EU-endpointen som förval när MAILGUN_ENDPOINT inte är satt', function () {
    expect(config('services.mailgun.endpoint'))->toBe('api.eu.mailgun.net');
});

it('.env.example har Mailguns nycklar och ingen POSTMARK_API_KEY', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('MAILGUN_DOMAIN=');
    expect($env)->toContain('MAILGUN_SECRET=');
    expect($env)->toContain('MAILGUN_ENDPOINT=api.eu.mailgun.net');
    expect($env)->not->toContain('POSTMARK_API_KEY');
});

it('composer.json kräver mailgun-mailer och http-client, inte postmark-mailer', function () {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $require = $composer['require'];

    expect($require)->toHaveKey('symfony/mailgun-mailer');
    expect($require)->toHaveKey('symfony/http-client');
    expect($require)->not->toHaveKey('symfony/postmark-mailer');
});
