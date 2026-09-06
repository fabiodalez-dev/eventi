<?php

return [
    'title' => 'Script e consenso', 'single' => 'script', 'name' => 'Nome del servizio', 'category' => 'Categoria cookie', 'enabled' => 'Attivo', 'order' => 'Ordine di esecuzione',
    'src' => 'Indirizzo HTTPS del file JavaScript (facoltativo)',
    'code' => 'Codice JavaScript (facoltativo)',
    'code_help' => 'Incolla solo JavaScript, senza tag <script>. Se compili anche l’indirizzo, questo codice viene eseguito prima del file esterno, per configurarlo.',
    'category_help' => 'Usa Necessari solo per funzioni indispensabili. Statistiche e Marketing vengono caricati esclusivamente dopo il consenso alla relativa categoria.',
    'help' => 'Qui configuri gli script, non il registro informativo dei cookie. I nuovi script sono spenti. La scelta del visitatore viene applicata al caricamento della pagina; la revoca blocca i caricamenti successivi ma non cancella automaticamente cookie di terze parti. Aggiorna anche il registro Cookie e le informative. Nessuno script viene eseguito nel pannello amministrativo.',
];
