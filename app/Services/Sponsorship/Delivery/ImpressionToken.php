<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Il gettone usa e getta di una visualizzazione.
 *
 * Ogni volta che una sponsorizzazione finisce in una pagina porta un gettone
 * nuovo: riga `sponsorships`, collocazione, minuto di emissione, 8 byte
 * casuali, e una firma HMAC con una chiave derivata da quella
 * dell'applicazione. Chi conta (P3) verifica la firma e la scadenza, poi usa
 * il numero casuale per la deduplica: lo stesso gettone conta una volta sola.
 *
 * Il numero casuale appartiene alla visita della pagina, non alla persona, e
 * non viene salvato sul dispositivo.
 *
 * **Nelle pagine in cache** il gettone non si scrive: si scrive il segnaposto
 * (`placeholder()`), e chi restituisce la copia salvata lo sostituisce con un
 * gettone fresco a ogni richiesta (`fillPlaceholders()`), come si fa con il
 * token CSRF. Così due visite alla stessa copia non portano lo stesso gettone.
 *
 * Forma: `v1.{riga}.{collocazione}.{minuto}.{numero}.{firma}`, tutto in
 * caratteri sicuri per un attributo HTML e un'intestazione HTTP.
 *
 * **Il gettone dell'occasione** (`o1.{città}.{collocazione}.{minuto}.{numero}.{firma}`)
 * accompagna lo spazio vuoto (`SponsorSlots::vacant()`): il segnale che lo
 * spazio è stato visto senza sponsorizzazione alimenta la previsione del
 * traffico, che decide quanto spazio ricevono le campagne a visualizzazioni.
 * Senza gettone bastava un ciclo per gonfiarla. Stessa chiave, un dominio
 * suo nella firma: un gettone di un tipo non vale mai per l'altro.
 */
final class ImpressionToken
{
    /** Il segnaposto nelle pagine in cache: `@@sponsor-token:ID:COLLOCAZIONE@@`. */
    public const PLACEHOLDER_PATTERN = '/@@sponsor-token:([1-9]\d{0,18}):([a-z_]{1,32})@@/';

    /** Il segnaposto del gettone dell'occasione: `@@sponsor-opportunity:CITTÀ:COLLOCAZIONE@@`. */
    public const OPPORTUNITY_PLACEHOLDER_PATTERN = '/@@sponsor-opportunity:([1-9]\d{0,9}):([a-z_]{1,32})@@/';

    /** Un gettone dell'occasione emesso, nella pagina. */
    public const OPPORTUNITY_TOKEN_PATTERN = '/\bo1\.([1-9]\d{0,9})\.([a-z_]{1,32})\.[1-9]\d{0,11}\.[0-9a-f]{16}\.[A-Za-z0-9_-]{43}\b/';

    private const OPPORTUNITY_VERSION = 'o1';

    private const OPPORTUNITY_PATTERN = '/\Ao1\.([1-9]\d{0,9})\.([a-z_]{1,32})\.([1-9]\d{0,11})\.([0-9a-f]{16})\.([A-Za-z0-9_-]{43})\z/';

    private const VERSION = 'v1';

    /* Niente zeri in testa: un numero si scrive in un modo solo, e il gettone pure. */
    private const PATTERN = '/\Av1\.([1-9]\d{0,18})\.([a-z_]{1,32})\.([1-9]\d{0,11})\.([0-9a-f]{16})\.([A-Za-z0-9_-]{43})\z/';

    /** Un minuto di tolleranza per un gettone che arriva «dal futuro»: due processi con orologi appena diversi. */
    private const FUTURE_TOLERANCE_MINUTES = 1;

    /** Quanti gettoni emessi ricorda un processo: una pagina ne porta poche decine. */
    private const ISSUED_MEMORY = 2000;

    /**
     * I gettoni che questo processo ha emesso con `issue()`, con la loro riga
     * e collocazione.
     *
     * Solo questi tornano segnaposto nella copia in cache
     * (`SponsorSlots::tokensToPlaceholders()`). Una stringa che ha solo la
     * forma di un gettone, per esempio scritta nel titolo di un evento, resta
     * com'è: se diventasse segnaposto, il server la firmerebbe da capo a ogni
     * visita, per una campagna e una collocazione scelte da chi l'ha scritta.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private static array $issued = [];

    private readonly string $key;

    public function __construct(
        #[Config('app.key')] string $appKey,
        #[Config('sponsorship.token_ttl_hours', 6)] private readonly int $ttlHours = 6,
    ) {
        if ($appKey === '') {
            throw new InvalidArgumentException('Impression tokens need the application key.');
        }

        if ($ttlHours < 1) {
            throw new InvalidArgumentException('An impression token lives at least one hour.');
        }

        /* Una chiave propria, derivata: un HMAC calcolato qui non vale come
           firma in nessun altro punto che usa la chiave dell'applicazione. */
        $this->key = hash_hmac('sha256', 'sponsorship-impression-token', $appKey, true);
    }

    /**
     * Un gettone nuovo per questa riga e questa collocazione, firmato adesso.
     *
     * È la porta che usano viste e risorse dell'API: non devono conoscere la
     * forma del gettone.
     */
    public static function issue(Sponsorship|int $sponsorship, SponsorshipPlacement|string $placement, ?CarbonInterface $now = null): string
    {
        $id = self::rowId($sponsorship);
        $token = app(self::class)->sign($id, $placement, $now ?? CarbonImmutable::now('UTC'));

        if (count(self::$issued) >= self::ISSUED_MEMORY) {
            array_shift(self::$issued);
        }

        self::$issued[$token] = [$id, self::placementValue($placement)];

        return $token;
    }

    /** Un gettone dell'occasione nuovo per questa città e collocazione, ricordato come quelli di `issue()`. */
    public static function issueOpportunity(int $cityId, SponsorshipPlacement|string $placement, ?CarbonInterface $now = null): string
    {
        $token = app(self::class)->signOpportunity($cityId, $placement, $now ?? CarbonImmutable::now('UTC'));

        if (count(self::$issued) >= self::ISSUED_MEMORY) {
            array_shift(self::$issued);
        }

        self::$issued[$token] = [$cityId, self::placementValue($placement)];

        return $token;
    }

    /** Il segnaposto da salvare nella copia in cache al posto del gettone dell'occasione. */
    public static function opportunityPlaceholder(int $cityId, SponsorshipPlacement|string $placement): string
    {
        if ($cityId < 1) {
            throw new InvalidArgumentException('An opportunity needs a city.');
        }

        return '@@sponsor-opportunity:'.$cityId.':'.self::placementValue($placement).'@@';
    }

    /**
     * Riga (o città, per un gettone dell'occasione) e collocazione di un
     * gettone emesso da questo processo con `issue()` o
     * `issueOpportunity()`, oppure `null` per qualunque altra stringa.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function issuedHere(string $token): ?array
    {
        return self::$issued[$token] ?? null;
    }

    /** Il segnaposto da salvare nella copia in cache al posto del gettone. */
    public static function placeholder(Sponsorship|int $sponsorship, SponsorshipPlacement|string $placement): string
    {
        $id = self::rowId($sponsorship);

        if ($id < 1) {
            throw new InvalidArgumentException('A placeholder needs a saved sponsorship.');
        }

        return '@@sponsor-token:'.$id.':'.self::placementValue($placement).'@@';
    }

    /** Sostituisce ogni segnaposto con un gettone fresco, diverso per ognuno. */
    public static function fillPlaceholders(string $html, ?CarbonInterface $now = null): string
    {
        if (! str_contains($html, '@@sponsor-token:') && ! str_contains($html, '@@sponsor-opportunity:')) {
            return $html;
        }

        $tokens = app(self::class);
        $now ??= CarbonImmutable::now('UTC');

        $html = (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $match): string => $tokens->sign((int) $match[1], $match[2], $now),
            $html,
        );

        return (string) preg_replace_callback(
            self::OPPORTUNITY_PLACEHOLDER_PATTERN,
            fn (array $match): string => $tokens->signOpportunity((int) $match[1], $match[2], $now),
            $html,
        );
    }

    /** Firma un gettone dell'occasione. `$nonce` si passa solo nei test. */
    public function signOpportunity(int $cityId, SponsorshipPlacement|string $placement, CarbonInterface $now, ?string $nonce = null): string
    {
        $placement = self::placementValue($placement);

        if ($cityId < 1) {
            throw new InvalidArgumentException('An opportunity token needs a city.');
        }

        $nonce ??= bin2hex(random_bytes(8));

        if (preg_match('/\A[0-9a-f]{16}\z/', $nonce) !== 1) {
            throw new InvalidArgumentException('The nonce is 8 bytes in lowercase hexadecimal.');
        }

        $minute = intdiv($now->getTimestamp(), 60);

        return self::OPPORTUNITY_VERSION.'.'.$cityId.'.'.$placement.'.'.$minute.'.'.$nonce.'.'.$this->mac(self::OPPORTUNITY_VERSION.'|'.$cityId.'|'.$placement.'|'.$minute.'|'.$nonce);
    }

    /**
     * Città, collocazione e numero casuale di un gettone dell'occasione,
     * oppure `null` se non è valido. Stesse regole di `verify()`.
     *
     * @return array{city: int, placement: string, nonce: string}|null
     */
    public function verifyOpportunity(string $token, CarbonInterface $now): ?array
    {
        if (preg_match(self::OPPORTUNITY_PATTERN, $token, $parts) !== 1) {
            return null;
        }

        [, $city, $placement, $minute, $nonce, $signature] = $parts;

        if (! hash_equals($this->mac(self::OPPORTUNITY_VERSION.'|'.$city.'|'.$placement.'|'.$minute.'|'.$nonce), $signature)) {
            return null;
        }

        $age = intdiv($now->getTimestamp(), 60) - (int) $minute;

        if ($age > $this->ttlHours * 60 || $age < -self::FUTURE_TOLERANCE_MINUTES) {
            return null;
        }

        return ['city' => (int) $city, 'placement' => $placement, 'nonce' => $nonce];
    }

    /**
     * Firma un gettone. `$nonce` si passa solo nei test: in produzione sono 8
     * byte casuali nuovi a ogni chiamata.
     */
    public function sign(int $sponsorshipId, SponsorshipPlacement|string $placement, CarbonInterface $now, ?string $nonce = null): string
    {
        $placement = self::placementValue($placement);

        if ($sponsorshipId < 1) {
            throw new InvalidArgumentException('An impression token needs a sponsorship.');
        }

        $nonce ??= bin2hex(random_bytes(8));

        if (preg_match('/\A[0-9a-f]{16}\z/', $nonce) !== 1) {
            throw new InvalidArgumentException('The nonce is 8 bytes in lowercase hexadecimal.');
        }

        $minute = intdiv($now->getTimestamp(), 60);

        return self::VERSION.'.'.$sponsorshipId.'.'.$placement.'.'.$minute.'.'.$nonce.'.'.$this->signature($sponsorshipId, $placement, $minute, $nonce);
    }

    /**
     * Cosa dice il gettone, oppure `null` se non è valido: forma sbagliata,
     * firma che non torna, scaduto, o emesso nel futuro.
     *
     * Non dice perché non è valido, di proposito: chi prova a falsificarlo non
     * deve sapere quale delle cose ha sbagliato.
     */
    public function verify(string $token, CarbonInterface $now): ?ImpressionTokenClaims
    {
        if (preg_match(self::PATTERN, $token, $parts) !== 1) {
            return null;
        }

        [, $id, $placement, $minute, $nonce, $signature] = $parts;

        if (! hash_equals($this->signature((int) $id, $placement, (int) $minute, $nonce), $signature)) {
            return null;
        }

        $age = intdiv($now->getTimestamp(), 60) - (int) $minute;

        if ($age > $this->ttlHours * 60 || $age < -self::FUTURE_TOLERANCE_MINUTES) {
            return null;
        }

        return new ImpressionTokenClaims(
            sponsorshipId: (int) $id,
            placement: $placement,
            issuedAt: CarbonImmutable::createFromTimestamp((int) $minute * 60, 'UTC'),
            nonce: $nonce,
        );
    }

    private function signature(int $sponsorshipId, string $placement, int $minute, string $nonce): string
    {
        return $this->mac(self::VERSION.'|'.$sponsorshipId.'|'.$placement.'|'.$minute.'|'.$nonce);
    }

    private function mac(string $message): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $this->key, true)), '+/', '-_'), '=');
    }

    /** L'identificativo della riga; zero se non è ancora salvata, e chi firma lo rifiuta. */
    private static function rowId(Sponsorship|int $sponsorship): int
    {
        if (is_int($sponsorship)) {
            return $sponsorship;
        }

        $key = $sponsorship->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    private static function placementValue(SponsorshipPlacement|string $placement): string
    {
        $value = $placement instanceof SponsorshipPlacement ? $placement->value : $placement;

        if (preg_match('/\A[a-z_]{1,32}\z/', $value) !== 1) {
            throw new InvalidArgumentException('A placement is lowercase letters and underscores.');
        }

        return $value;
    }
}
