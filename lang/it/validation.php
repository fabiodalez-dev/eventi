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

    'custom' => [],

    'attributes' => [],

];
