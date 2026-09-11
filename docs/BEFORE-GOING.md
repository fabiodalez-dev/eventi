# Prima di andare

La scheda evento dispone di una sezione dedicata, dopo il salvataggio e la descrizione. Il collegamento «Tutte le date di questo evento» appare sotto la descrizione soltanto nelle pagine di una data quando esistono altre date.

## Compilazione

In ogni pannello eventi (admin, locale, organizzatore):

- Tessera e accesso in sedia a rotelle hanno una scelta esplicita; i valori non dichiarati non producono un’etichetta pubblica. L’accessibilità può ereditare le informazioni del luogo, la tessera è sempre dell’evento.
- «Caratteristiche e servizi» è una selezione multipla ricercabile, raggruppata come un catalogo di tag.
- L’admin può creare una caratteristica direttamente dal selettore oppure da **Contenuti → Prima di andare**. Nome, gruppo, descrizione, icona e disponibilità sono condivisi fra gli eventi. Le icone sono selezionate da una galleria di 29 simboli con anteprima, senza HTML o SVG arbitrario.
- «Altre informazioni per questo evento» permette fino a 12 voci libere con titolo, icona e dettagli. Queste rimangono specifiche dell’evento.
- Le altre note editoriali esistenti restano disponibili e vengono presentate nella stessa sezione con icone.

Il catalogo non è un nuovo filtro pubblico: tessera e accessibilità continuano ad alimentare i filtri strutturati esistenti. Una voce disattivata non compare più sul frontend. Le quattro voci automatiche di tessera/accessibilità permettono di modificare nome e icona, ma non di cancellarle o disattivarle dall’interfaccia.

## Dati e API

`event_features` conserva il catalogo. `events.content_details.feature_ids` conserva le selezioni; `practical_custom` le voci libere. L’API espone `content_details.practical_items`, con `label`, `icon` e `text`: web e Android leggono le stesse informazioni. Il catalogo viene memorizzato come array scalari per compatibilità con la cache file; le modifiche invalidano il catalogo e le pagine.

## Seed

`php artisan db:seed --class=EventFeatureSeeder` aggiunge 53 voci, incluse quelle automatiche, senza sovrascrivere personalizzazioni esistenti.

`php artisan db:seed --class=BeforeGoingDemoSeeder` crea in locale/test un evento dimostrativo con più caratteristiche e due voci libere. È marcato demo e non indicizzabile. Il seeder non modifica gli eventi esistenti né opera in produzione.
