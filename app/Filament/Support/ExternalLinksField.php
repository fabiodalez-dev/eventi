<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DTOs\ExternalLink;
use App\DTOs\ExternalLinkList;
use App\Rules\ExternalLinks;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

/**
 * Il ripetitore dei link esterni, uno per entrambi i pannelli.
 *
 * La struttura è la stessa in `/admin` e in `/gestione` — sono le stesse
 * righe della stessa colonna — ma **le parole no**: il dizionario arriva per
 * parametro, perché `lang/it/admin.php` e `lang/it/manage.php` restano
 * separati di proposito (la redazione e un gestore non chiamano le cose allo
 * stesso modo, e il giorno in cui una delle due lingue cambia l'altra non
 * deve cambiare con lei).
 *
 * **La validazione è una sola.** I campi interni non portano regole proprie:
 * `->required()` e `->url()` di Filament produrrebbero un secondo messaggio
 * accanto a quello della regola, con parole diverse per lo stesso difetto.
 * Qui c'è `App\Rules\ExternalLinks` e basta; `maxlength` sull'etichetta è un
 * attributo HTML, non una regola, e serve solo a fermare la digitazione prima
 * che diventi un errore.
 *
 * `maxItems()` invece resta: nasconde il pulsante "aggiungi" all'ottava riga,
 * cioè impedisce il difetto invece di raccontarlo dopo. Il numero è lo stesso
 * costante che legge la regola.
 */
final class ExternalLinksField
{
    /**
     * @param  'admin'|'manage'  $dictionary  il file di `lang/it` da cui prendere le parole
     */
    public static function make(string $dictionary): Repeater
    {
        return Repeater::make('external_links')
            ->columnSpanFull()
            ->hiddenLabel()
            ->helperText(__($dictionary.'.hints.external_links', ['max' => ExternalLinkList::MAX_LINKS]))
            ->addActionLabel(__($dictionary.'.actions.add_external_link'))
            ->defaultItems(0)
            ->maxItems(ExternalLinkList::MAX_LINKS)
            ->reorderableWithButtons()
            ->collapsible()
            ->itemLabel(static fn (array $state): ?string => self::itemLabel($state))
            ->columns(2)
            ->rules([new ExternalLinks])
            ->schema([
                TextInput::make('label')
                    ->label(__($dictionary.'.fields.external_link_label'))
                    ->datalist(ExternalLink::suggestedLabels())
                    ->extraInputAttributes(['maxlength' => ExternalLink::MAX_LABEL_LENGTH]),

                TextInput::make('url')
                    ->label(__($dictionary.'.fields.external_link_url'))
                    ->placeholder('https://…')
                    ->inputMode('url'),
            ]);
    }

    /**
     * Il titolo della riga chiusa. Con l'etichetta ancora vuota non si
     * inventa niente: Filament mostra il numero della riga, che è
     * esattamente ciò a cui si riferiscono i messaggi della regola.
     *
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $label = is_scalar($state['label'] ?? null) ? trim((string) $state['label']) : '';

        return $label === '' ? null : $label;
    }
}
