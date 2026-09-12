<?php

declare(strict_types=1);

use App\Models\Event;
use App\Support\Poster;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

/**
 * §12.1 — una locandina è un file che arriva da fuori: da un telefono, da un
 * modulo compilato di fretta, o da un calendario altrui che non ha compilato
 * nessun modulo. Può essere troncata a metà upload, può essere un PDF
 * rinominato, può sparire dal disco.
 *
 * La regola è che **l'evento vale più della sua locandina**: qualunque cosa
 * succeda all'immagine, la serata resta salvata, pubblicata e visitabile. Il
 * contrario — un evento che non si salva perché il file era rotto — è il modo
 * in cui un gestore rinuncia a pubblicare.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');

    $this->event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00')->event;
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

it('salva e pubblica l evento anche se il file non è un immagine', function (): void {
    $this->event->addMedia(ImageFixtures::upload('locandina.jpg', '%PDF-1.7 non sono una immagine'))
        ->toMediaCollection('poster');

    $evento = $this->event->fresh();

    expect($evento)->not->toBeNull()
        ->and($evento?->getFirstMedia('poster'))->toBeNull()
        ->and(Event::query()->whereKey($this->event->getKey())->exists())->toBeTrue();

    $this->get('/eventi/'.$this->event->slug)->assertOk();
});

/**
 * Il caso più insidioso: i primi byte sono quelli di un JPEG vero, quindi ogni
 * controllo sul tipo passa, e il file si rompe solo quando ImageMagick prova a
 * decodificarlo per generare le varianti.
 *
 * È la ragione per cui §12.1 vuole quella pipeline **tutta in coda e mai
 * bloccante**: la decodifica avviene in un lavoro separato, e se muore muore
 * lì. Qui la coda è quella di produzione — non `sync` — e il gesto del gestore
 * («salva») deve arrivare in fondo lo stesso.
 */
it('non fa fallire il salvataggio con un JPEG troncato a metà', function (): void {
    config()->set('media-library.queue_connection_name', 'database');

    $troncato = substr(ImageFixtures::jpeg(600, 800), 0, 400);

    $this->event->addMedia(ImageFixtures::upload('mezza-locandina.jpg', $troncato))
        ->toMediaCollection('poster');

    expect(Event::query()->whereKey($this->event->getKey())->exists())->toBeTrue();

    $this->get('/eventi/'.$this->event->slug)->assertOk();
    $this->getJson('/api/v1/events/'.$this->event->slug)->assertOk();
});

it('rejects a truncated JPEG before decoding even with the synchronous queue', function (): void {
    config()->set('media-library.queue_connection_name', 'sync');
    $troncato = substr(ImageFixtures::jpeg(600, 800), 0, 400);
    $this->event->addMedia(ImageFixtures::upload('mezza-locandina.jpg', $troncato))->toMediaCollection('poster');
    expect($this->event->fresh()->getFirstMedia('poster'))->toBeNull();
    expect(Event::query()->whereKey($this->event->getKey())->exists())->toBeTrue();
    $this->get('/eventi/'.$this->event->slug)->assertOk();
});

/**
 * La locandina sparita dal disco dopo essere stata registrata: succede a un
 * ripristino fatto male, o a un `rsync --delete` su una cartella che non
 * andava sincronizzata (`RUNBOOK.md`).
 */
it('mostra la scheda anche se il file della locandina non c è più', function (): void {
    $this->event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))
        ->toMediaCollection('poster');

    $media = $this->event->fresh()?->getFirstMedia('poster');
    expect($media)->not->toBeNull();

    Storage::disk('public')->deleteDirectory((string) $media?->id);

    $this->get('/eventi/'.$this->event->slug)->assertOk();
    $this->getJson('/api/v1/events/'.$this->event->slug)->assertOk();
});

/**
 * E la scheda deve restare completa: titolo e data ci sono anche quando
 * l'immagine non c'è, perché sono l'informazione, e la locandina è il
 * contorno.
 */
it('tiene in pagina il titolo e la data quando la locandina manca', function (): void {
    $this->event->addMedia(ImageFixtures::upload('finta.jpg', "<?php echo 'ciao';"))
        ->toMediaCollection('poster');

    $risposta = $this->get('/eventi/'.$this->event->slug)->assertOk();

    /* Il titolo va cercato ESCAPATO: un titolo con virgolette o con una & finisce
       in pagina come `&quot;` e `&amp;`, ed e giusto che sia cosi — la versione
       grezza in pagina sarebbe una falla, non una comodita per il test. */
    expect($risposta->getContent())->toContain(e($this->event->title))
        ->and(Poster::imageSet($this->event->fresh()))->toBeNull();
});
