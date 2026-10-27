<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/SendingHelpers.php';
require_once __DIR__.'/InterfaceHelpers.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->afterEach(fn () => cleanUpCsvFiles());

/*
| Le texte visible d'une page, sans balises, entités décodées et espaces réduits : une phrase écrite sur plusieurs
| lignes d'un gabarit, ou avec une apostrophe, se retrouve telle qu'on la lit.
*/
function visibleText(TestResponse $response): string
{
    $text = html_entity_decode(strip_tags($response->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

TestResponse::macro('assertShows', function (string $text) {
    Assert::assertStringContainsString(
        trim((string) preg_replace('/\s+/u', ' ', $text)),
        visibleText($this),
        "Le texte « {$text} » n'est pas affiché sur la page.",
    );

    return $this;
});

TestResponse::macro('assertDoesNotShow', function (string $text) {
    Assert::assertStringNotContainsString(
        trim((string) preg_replace('/\s+/u', ' ', $text)),
        visibleText($this),
        "Le texte « {$text} » ne devrait pas être affiché sur la page.",
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
