<?php

declare(strict_types=1);

/*
 * Messaggi di validazione in italiano.
 *
 * Laravel non pubblica questo file per impostazione predefinita e ricade sulla
 * lingua di riserva: senza, un modulo compilato male risponderebbe in inglese
 * a un pubblico italiano, che è una stringa di interfaccia non tradotta come
 * tutte le altre (§5 delle convenzioni).
 *
 * Sono tradotte le regole realmente usate dai moduli pubblici e dai pannelli,
 * più quelle di uso comune. I nomi dei campi non stanno qui: ogni Form Request
 * li dichiara con `attributes()` leggendo `lang/it/forms.php`, dove stanno
 * anche le etichette mostrate sopra al campo.
 */
return [

    'accepted' => 'Devi accettare :attribute.',
    'active_url' => ':attribute non è un indirizzo valido.',
    'after' => ':attribute deve essere una data successiva al :date.',
    'after_or_equal' => ':attribute deve essere una data uguale o successiva al :date.',
    'alpha' => ':attribute può contenere solo lettere.',
    'alpha_dash' => ':attribute può contenere solo lettere, numeri, trattini e trattini bassi.',
    'alpha_num' => ':attribute può contenere solo lettere e numeri.',
    'array' => ':attribute deve essere un elenco.',
    'before' => ':attribute deve essere una data precedente al :date.',
    'before_or_equal' => ':attribute deve essere una data uguale o precedente al :date.',
    'between' => [
        'array' => ':attribute deve contenere fra :min e :max elementi.',
        'file' => ':attribute deve pesare fra :min e :max kilobyte.',
        'numeric' => ':attribute deve essere compreso fra :min e :max.',
        'string' => ':attribute deve contenere fra :min e :max caratteri.',
    ],
    'boolean' => ':attribute può valere solo sì o no.',
    'confirmed' => 'La conferma di :attribute non coincide.',
    'current_password' => 'La password non è corretta.',
    'date' => ':attribute non è una data valida.',
    'date_equals' => ':attribute deve essere una data uguale al :date.',
    'date_format' => ':attribute non corrisponde al formato :format.',
    'different' => ':attribute e :other devono essere diversi.',
    'digits' => ':attribute deve essere di :digits cifre.',
    'digits_between' => ':attribute deve avere fra :min e :max cifre.',
    'email' => ':attribute non è un indirizzo email valido.',
    'ends_with' => ':attribute deve terminare con uno di questi valori: :values.',
    'exists' => ':attribute selezionato non è valido.',
    'file' => ':attribute deve essere un file.',
    'filled' => ':attribute non può essere vuoto.',
    'gt' => [
        'array' => ':attribute deve contenere più di :value elementi.',
        'file' => ':attribute deve pesare più di :value kilobyte.',
        'numeric' => ':attribute deve essere maggiore di :value.',
        'string' => ':attribute deve contenere più di :value caratteri.',
    ],
    'gte' => [
        'array' => ':attribute deve contenere almeno :value elementi.',
        'file' => ':attribute deve pesare almeno :value kilobyte.',
        'numeric' => ':attribute deve essere maggiore o uguale a :value.',
        'string' => ':attribute deve contenere almeno :value caratteri.',
    ],
    'image' => ':attribute deve essere un\'immagine.',
    'in' => ':attribute selezionato non è valido.',
    'integer' => ':attribute deve essere un numero intero.',
    'ip' => ':attribute deve essere un indirizzo IP valido.',
    'ipv4' => ':attribute deve essere un indirizzo IPv4 valido.',
    'ipv6' => ':attribute deve essere un indirizzo IPv6 valido.',
    'json' => ':attribute deve essere una stringa JSON valida.',
    'lt' => [
        'array' => ':attribute deve contenere meno di :value elementi.',
        'file' => ':attribute deve pesare meno di :value kilobyte.',
        'numeric' => ':attribute deve essere minore di :value.',
        'string' => ':attribute deve contenere meno di :value caratteri.',
    ],
    'lte' => [
        'array' => ':attribute non può contenere più di :value elementi.',
        'file' => ':attribute non può pesare più di :value kilobyte.',
        'numeric' => ':attribute deve essere minore o uguale a :value.',
        'string' => ':attribute non può contenere più di :value caratteri.',
    ],
    'max' => [
        'array' => ':attribute non può contenere più di :max elementi.',
        'file' => ':attribute non può pesare più di :max kilobyte.',
        'numeric' => ':attribute non può essere maggiore di :max.',
        'string' => ':attribute non può superare i :max caratteri.',
    ],
    'mimes' => ':attribute deve essere un file di tipo: :values.',
    'mimetypes' => ':attribute deve essere un file di tipo: :values.',
    'min' => [
        'array' => ':attribute deve contenere almeno :min elementi.',
        'file' => ':attribute deve pesare almeno :min kilobyte.',
        'numeric' => ':attribute deve essere almeno :min.',
        'string' => ':attribute deve contenere almeno :min caratteri.',
    ],
    'not_in' => ':attribute selezionato non è valido.',
    'not_regex' => 'Il formato di :attribute non è valido.',
    'numeric' => ':attribute deve essere un numero.',
    'prohibited' => ':attribute non può essere compilato.',
    'prohibited_if' => ':attribute non può essere compilato quando :other vale :value.',
    'regex' => 'Il formato di :attribute non è valido.',
    'required' => ':attribute è obbligatorio.',
    'required_if' => ':attribute è obbligatorio quando :other vale :value.',
    'required_with' => ':attribute è obbligatorio quando :values è presente.',
    'required_without' => ':attribute è obbligatorio quando :values non è presente.',
    'same' => ':attribute e :other devono coincidere.',
    'size' => [
        'array' => ':attribute deve contenere :size elementi.',
        'file' => ':attribute deve pesare :size kilobyte.',
        'numeric' => ':attribute deve valere :size.',
        'string' => ':attribute deve contenere :size caratteri.',
    ],
    'starts_with' => ':attribute deve iniziare con uno di questi valori: :values.',
    'string' => ':attribute deve essere un testo.',
    'timezone' => ':attribute deve essere un fuso orario valido.',
    'unique' => ':attribute è già stato usato.',
    'uploaded' => 'Il caricamento di :attribute non è riuscito.',
    'url' => ':attribute deve essere un indirizzo valido.',
    'uuid' => ':attribute deve essere un UUID valido.',

    /*
     * Messaggi della pipeline media (§12.1). Stanno qui e non dentro la regola
     * perché sono testo, e il testo vive in lang/it (§2 delle convenzioni).
     */
    'custom' => [
        'image' => [
            'invalid' => 'Il file caricato non è valido.',
            'upload_failed' => 'Il caricamento non è andato a buon fine: riprova.',
            'too_large' => 'L\'immagine supera :max MB.',
            'unsupported' => 'Formato non riconosciuto. Sono ammessi: :formats.',
            'extension_mismatch' => 'Il file si presenta come «:declared» ma è un «:real»: rinominalo con l\'estensione giusta.',
            'too_small' => 'L\'immagine è troppo piccola: servono almeno :width×:height pixel.',
        ],

        /*
         * Link esterni di un evento (App\Rules\ExternalLinks). Ogni messaggio
         * dice **quale** riga non va: un elenco ripetibile senza il numero
         * della riga costringe a ricontrollarle tutte.
         */
        'external_links' => [
            'invalid' => 'I link esterni non sono in un formato leggibile.',
            'too_many' => 'Non puoi aggiungere più di :max link a un evento.',
            'row_invalid' => 'Il link numero :position non è leggibile.',
            'label_required' => 'Il link numero :position non ha un\'etichetta: scrivi come si chiama.',
            'label_too_long' => 'L\'etichetta del link numero :position supera i :max caratteri.',
            'url_required' => 'Il link numero :position non ha un indirizzo.',
            'url_scheme' => 'Il link numero :position deve iniziare con :schemes.',
            'url_host' => 'Il link numero :position non ha un indirizzo valido: controlla il dominio.',
            'url_credentials' => 'Il link numero :position contiene credenziali prima del dominio: togli la parte prima della chiocciola.',
        ],

        /* Le righe di una scheda tecnica: `events.facts` e `venues.info`. */
        'facts' => [
            'invalid' => 'La scheda tecnica non è in un formato leggibile.',
            'too_many' => 'Non puoi aggiungere più di :max righe alla scheda tecnica.',
            'row_invalid' => 'La riga numero :position non è leggibile.',
            'label_required' => 'La riga numero :position non ha un\'etichetta: scrivi di cosa si tratta.',
            'label_too_long' => 'L\'etichetta della riga numero :position supera i :max caratteri.',
            'value_required' => 'La riga numero :position non ha un valore: un\'etichetta da sola resta senza risposta.',
            'value_too_long' => 'Il valore della riga numero :position supera i :max caratteri.',
        ],

        /* Le righe di «Come arrivare»: `venues.transit`. */
        'transit' => [
            'invalid' => 'Le indicazioni per arrivare non sono in un formato leggibile.',
            'too_many' => 'Non puoi aggiungere più di :max indicazioni.',
            'row_invalid' => 'L\'indicazione numero :position non è leggibile.',
            'mode_required' => 'L\'indicazione numero :position non dice con quale mezzo: scegline uno.',
            'text_required' => 'L\'indicazione numero :position è vuota: scrivi quale linea e quale fermata.',
            'text_too_long' => 'L\'indicazione numero :position supera i :max caratteri.',
        ],

        /*
         * Turnstile (§14.7). Chi legge questi messaggi è una persona vera a
         * cui la verifica non è riuscita: dicono cosa fare, non cosa è andato
         * storto dentro.
         */
        'turnstile' => [
            'missing' => 'Completa la verifica antispam prima di inviare.',
            'failed' => 'La verifica antispam non è riuscita: ricaricala e riprova.',
        ],
    ],

    'attributes' => [],

];
