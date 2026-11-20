<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\Delivery\ImpressionTokenClaims;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/*
 * Il gettone usa e getta di una visualizzazione: firma, scadenza,
 * manomissione, e il segnaposto delle pagine in cache.
 *
 * Quasi tutto si prova costruendo l'oggetto a mano, con una chiave e un
 * orologio scritti qui: nessun database. Il contenitore serve solo alle
 * porte statiche (`issue`, `fillPlaceholders`).
 */
uses(TestCase::class);

const P0_KEY = 'base64:Zm9yLXRlc3RzLW9ubHktbm90LWEtcmVhbC1rZXktMDA=';
const P0_NONCE = '0123456789abcdef';

function p0Tokens(int $ttlHours = 6, string $key = P0_KEY): ImpressionToken
{
    return new ImpressionToken($key, $ttlHours);
}

function p0Now(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-20 18:30:42', 'UTC');
}

it('signs a token that verifies back to the same row, placement, minute and nonce', function (): void {
    $token = p0Tokens()->sign(42, SponsorshipPlacement::HomeCard, p0Now(), P0_NONCE);
    $claims = p0Tokens()->verify($token, p0Now());

    expect($claims)->toBeInstanceOf(ImpressionTokenClaims::class)
        ->and($claims->sponsorshipId)->toBe(42)
        ->and($claims->placement)->toBe('home_card')
        ->and($claims->nonce)->toBe(P0_NONCE)
        ->and($claims->issuedAt->toIso8601String())->toBe('2026-11-20T18:30:00+00:00')
        ->and($claims->placementCase())->toBe(SponsorshipPlacement::HomeCard);
});

it('writes the token in characters safe for an HTML attribute and an HTTP header', function (): void {
    $token = p0Tokens()->sign(9_999_999, 'list_top', p0Now());

    expect($token)->toMatch('/\Av1\.9999999\.list_top\.\d+\.[0-9a-f]{16}\.[A-Za-z0-9_-]{43}\z/')
        ->and(strlen($token))->toBeLessThan(128);
});

it('gives every page view a different token, even for the same row in the same minute', function (): void {
    $tokens = [];

    for ($i = 0; $i < 200; $i++) {
        $tokens[] = p0Tokens()->sign(7, 'home_card', p0Now());
    }

    expect(array_unique($tokens))->toHaveCount(200);
});

it('is repeatable when the nonce is fixed', function (): void {
    expect(p0Tokens()->sign(7, 'home_card', p0Now(), P0_NONCE))
        ->toBe(p0Tokens()->sign(7, 'home_card', p0Now()->addSeconds(10), P0_NONCE));
});

it('stays valid for exactly the configured hours and not one minute more', function (): void {
    $token = p0Tokens()->sign(42, 'home_card', p0Now(), P0_NONCE);
    $issued = p0Now()->startOfMinute();

    expect(p0Tokens()->verify($token, $issued->addHours(6)->addSeconds(59)))->not->toBeNull()
        ->and(p0Tokens()->verify($token, $issued->addHours(6)->addMinute()))->toBeNull()
        ->and(p0Tokens(ttlHours: 1)->verify($token, $issued->addMinutes(61)))->toBeNull()
        ->and(p0Tokens(ttlHours: 1)->verify($token, $issued->addMinutes(60)))->not->toBeNull();
});

it('accepts a token from one minute ahead and refuses one from further in the future', function (): void {
    $token = p0Tokens()->sign(42, 'home_card', p0Now(), P0_NONCE);

    expect(p0Tokens()->verify($token, p0Now()->subMinute()))->not->toBeNull()
        ->and(p0Tokens()->verify($token, p0Now()->startOfMinute()->subMinutes(2)))->toBeNull();
});

it('refuses a token signed with another key', function (): void {
    $token = p0Tokens(key: 'base64:YW5vdGhlci1rZXktYW5vdGhlci1rZXktYW5vdGhlci0x')->sign(42, 'home_card', p0Now(), P0_NONCE);

    expect(p0Tokens()->verify($token, p0Now()))->toBeNull();
});

it('refuses a token whose parts were changed', function (Closure $tamper): void {
    $token = p0Tokens()->sign(42, 'home_card', p0Now(), P0_NONCE);

    expect(p0Tokens()->verify($tamper($token), p0Now()))->toBeNull();
})->with([
    'another row' => [fn (string $t): string => str_replace('v1.42.', 'v1.43.', $t)],
    'another placement' => [fn (string $t): string => str_replace('.home_card.', '.home_hero.', $t)],
    'an older minute' => [function (string $t): string {
        $parts = explode('.', $t);
        $parts[3] = (string) ((int) $parts[3] - 1);

        return implode('.', $parts);
    }],
    'another nonce' => [fn (string $t): string => str_replace(P0_NONCE, 'fedcba9876543210', $t)],
    'one character of the signature' => [function (string $t): string {
        $last = substr($t, -1);

        return substr($t, 0, -1).($last === 'A' ? 'B' : 'A');
    }],
    'a zero in front of the row' => [fn (string $t): string => str_replace('v1.42.', 'v1.042.', $t)],
    'another version' => [fn (string $t): string => 'v2'.substr($t, 2)],
    'trailing characters' => [fn (string $t): string => $t.'x'],
    'a new line at the end' => [fn (string $t): string => $t."\n"],
    'uppercase nonce' => [fn (string $t): string => str_replace(P0_NONCE, strtoupper(P0_NONCE), $t)],
]);

it('refuses strings that are not tokens at all', function (string $garbage): void {
    expect(p0Tokens()->verify($garbage, p0Now()))->toBeNull();
})->with(['', 'v1', 'v1.....', 'null', str_repeat('a', 500), 'v1.42.home_card.1.0123456789abcdef.']);

it('refuses to sign with bad input', function (Closure $sign): void {
    $sign();
})->with([
    'no row' => [fn () => p0Tokens()->sign(0, 'home_card', p0Now())],
    'placement with spaces' => [fn () => p0Tokens()->sign(1, 'home card', p0Now())],
    'placement with a dot' => [fn () => p0Tokens()->sign(1, 'home.card', p0Now())],
    'short nonce' => [fn () => p0Tokens()->sign(1, 'home_card', p0Now(), 'abc')],
    'uppercase nonce' => [fn () => p0Tokens()->sign(1, 'home_card', p0Now(), 'ABCDEF0123456789')],
])->throws(InvalidArgumentException::class);

it('refuses to work without a key or with a lifetime under one hour', function (Closure $build): void {
    $build();
})->with([
    'empty key' => [fn () => new ImpressionToken('')],
    'zero hours' => [fn () => new ImpressionToken(P0_KEY, 0)],
])->throws(InvalidArgumentException::class);

it('says which row and placement the token belongs to', function (): void {
    $claims = p0Tokens()->verify(p0Tokens()->sign(42, 'home_card', p0Now(), P0_NONCE), p0Now());

    expect($claims->isFor(42))->toBeTrue()
        ->and($claims->isFor(42, SponsorshipPlacement::HomeCard))->toBeTrue()
        ->and($claims->isFor(42, 'home_card'))->toBeTrue()
        ->and($claims->isFor(42, SponsorshipPlacement::ListTop))->toBeFalse()
        ->and($claims->isFor(43))->toBeFalse();
});

it('keeps a placement outside the four as a plain string', function (): void {
    $claims = p0Tokens()->verify(p0Tokens()->sign(5, 'banner', p0Now(), P0_NONCE), p0Now());

    expect($claims->placement)->toBe('banner')
        ->and($claims->placementCase())->toBeNull();
});

it('issues tokens from the container with the application key and the configured lifetime', function (): void {
    config(['sponsorship.token_ttl_hours' => 2]);
    app()->forgetInstance(ImpressionToken::class);

    $token = ImpressionToken::issue(42, SponsorshipPlacement::ListTop, p0Now());
    $tokens = app(ImpressionToken::class);

    expect($tokens->verify($token, p0Now()->addHours(2)))->not->toBeNull()
        ->and($tokens->verify($token, p0Now()->startOfMinute()->addHours(2)->addMinute()))->toBeNull()
        ->and((new ImpressionToken((string) config('app.key')))->verify($token, p0Now()))->not->toBeNull();
});

it('issues for the current minute when no time is given', function (): void {
    $this->travelTo(p0Now());

    $claims = app(ImpressionToken::class)->verify(ImpressionToken::issue(42, 'home_card'), p0Now());

    expect($claims->issuedAt->toIso8601String())->toBe('2026-11-20T18:30:00+00:00');
});

it('writes the cache placeholder in the agreed form', function (): void {
    expect(ImpressionToken::placeholder(12, SponsorshipPlacement::HomeHero))->toBe('@@sponsor-token:12:home_hero@@')
        ->and(ImpressionToken::placeholder(12, 'list_top'))->toBe('@@sponsor-token:12:list_top@@')
        ->and(preg_match(ImpressionToken::PLACEHOLDER_PATTERN, ImpressionToken::placeholder(12, 'home_hero')))->toBe(1);
});

it('refuses a placeholder for a row that is not saved', function (): void {
    ImpressionToken::placeholder(0, 'home_hero');
})->throws(InvalidArgumentException::class);

it('fills every placeholder of a cached page with its own fresh token', function (): void {
    $html = '<a data-sponsorship-token="'.ImpressionToken::placeholder(12, 'home_hero').'">x</a>'
        .'<a data-sponsorship-token="'.ImpressionToken::placeholder(12, 'home_hero').'">y</a>'
        .'<a data-sponsorship-token="'.ImpressionToken::placeholder(31, 'home_card').'">z</a>';

    $first = ImpressionToken::fillPlaceholders($html, p0Now());
    $second = ImpressionToken::fillPlaceholders($html, p0Now());

    preg_match_all('/data-sponsorship-token="([^"]+)"/', $first, $found);
    $claims = array_map(fn (string $token) => app(ImpressionToken::class)->verify($token, p0Now()), $found[1]);

    expect($first)->not->toContain('@@sponsor-token')
        ->and($first)->not->toBe($second)
        ->and(array_unique($found[1]))->toHaveCount(3)
        ->and(array_map(fn (?ImpressionTokenClaims $c): string => $c->sponsorshipId.':'.$c->placement, $claims))
        ->toBe(['12:home_hero', '12:home_hero', '31:home_card']);
});

it('leaves a page without placeholders exactly as it is', function (): void {
    $html = '<p>@@sponsor-token:abc:home_hero@@ e @@altro@@</p>';

    expect(ImpressionToken::fillPlaceholders($html, p0Now()))->toBe($html);
});

it('signs an opportunity token that verifies back to city, placement and nonce, and never as an impression', function (): void {
    $token = p0Tokens()->signOpportunity(3, SponsorshipPlacement::ListTop, p0Now(), P0_NONCE);

    expect($token)->toStartWith('o1.3.list_top.')
        ->and(p0Tokens()->verifyOpportunity($token, p0Now()))->toBe(['city' => 3, 'placement' => 'list_top', 'nonce' => P0_NONCE])
        ->and(p0Tokens()->verify($token, p0Now()))->toBeNull()
        ->and(p0Tokens()->verifyOpportunity(p0Tokens()->sign(3, 'list_top', p0Now(), P0_NONCE), p0Now()))->toBeNull()
        /* Scaduto, dal futuro, con un'altra chiave, manomesso. */
        ->and(p0Tokens()->verifyOpportunity($token, p0Now()->addHours(6)->addMinutes(1)))->toBeNull()
        ->and(p0Tokens()->verifyOpportunity($token, p0Now()->addHours(6)))->not->toBeNull()
        ->and(p0Tokens()->verifyOpportunity($token, p0Now()->subMinutes(2)))->toBeNull()
        ->and(p0Tokens(key: 'altra-chiave')->verifyOpportunity($token, p0Now()))->toBeNull()
        ->and(p0Tokens()->verifyOpportunity(str_replace('o1.3.', 'o1.4.', $token), p0Now()))->toBeNull();
});

it('refuses an opportunity token without a city or with a bad nonce', function (int $city, string $nonce): void {
    p0Tokens()->signOpportunity($city, 'list_top', p0Now(), $nonce);
})->with([[0, P0_NONCE], [3, 'XYZ']])->throws(InvalidArgumentException::class);

it('remembers only the tokens this process issued', function (): void {
    $issued = ImpressionToken::issue(12, SponsorshipPlacement::HomeCard);
    $opportunity = ImpressionToken::issueOpportunity(5, 'list_top');
    $elsewhere = app(ImpressionToken::class)->sign(12, 'home_card', CarbonImmutable::now('UTC'));

    expect(ImpressionToken::issuedHere($issued))->toBe([12, 'home_card'])
        ->and(ImpressionToken::issuedHere($opportunity))->toBe([5, 'list_top'])
        ->and(ImpressionToken::issuedHere($elsewhere))->toBeNull()
        ->and(ImpressionToken::opportunityPlaceholder(5, 'list_top'))->toBe('@@sponsor-opportunity:5:list_top@@');
});

it('fills opportunity placeholders with a fresh token each', function (): void {
    $html = ImpressionToken::fillPlaceholders('<div data-t="@@sponsor-opportunity:5:list_top@@"></div><div data-t="@@sponsor-opportunity:5:list_top@@"></div>');

    preg_match_all('/data-t="([^"]+)"/', $html, $found);

    expect($found[1])->toHaveCount(2)
        ->and($found[1][0])->not->toBe($found[1][1])
        ->and(app(ImpressionToken::class)->verifyOpportunity($found[1][0], CarbonImmutable::now('UTC')))->toMatchArray(['city' => 5, 'placement' => 'list_top']);
});

it('refuses an opportunity placeholder without a city', function (): void {
    ImpressionToken::opportunityPlaceholder(0, 'list_top');
})->throws(InvalidArgumentException::class);
