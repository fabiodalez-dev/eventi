# Layout dei moduli

I moduli con sezioni nei pannelli admin e locali hanno una sola colonna esterna (`$schema->columns(1)`). Ogni sezione usa tutta la larghezza disponibile; non si affiancano sezioni che contengono ulteriori griglie di campi.

- All’interno di una sezione: una colonna su mobile, al massimo due da `lg`.
- Spiegazioni lunghe, editor e gruppi complessi restano a tutta larghezza.
- Repeater e fieldset con colonne interne devono usare `columnSpanFull()`.
- Non usare `columnSpan(2)` senza breakpoint: forza colonne implicite anche su mobile. Usare `['default' => 1, 'lg' => 2]` oppure `columnSpanFull()`.
- I moduli piatti, senza sezioni annidate, possono avere due colonne desktop.
- Non applicare queste regole alle tabelle, ai calendari o alle griglie di schede del sito: hanno una struttura e una funzione differenti.

`tests/Feature/Admin/FormLayoutTest.php` protegge la struttura esterna delle risorse e i repeater condivisi. Verificare anche il rendering dei pannelli e il browser a 390, 768 e 1440 px quando si cambia il layout.
