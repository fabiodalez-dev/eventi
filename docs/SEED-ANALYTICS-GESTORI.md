# Dati dimostrativi gestori

Seed esplicito, non incluso in DatabaseSeeder né eseguito automaticamente dal deploy.
Locale: Officina dei Portici. Organizzatore: Collettivo Portici Aperti.
Account dedicato: `gestore-officina-portici@example.test`, senza permessi amministrativi.
Password casuale mostrata solo alla prima creazione, mai inserita nel repository;
le esecuzioni successive preservano la password esistente.

## Dataset richiesto il 15 settembre 2026

```sh
php artisan analytics:demo padova --week=2026-09-14 --as-of=2026-09-15
```

Sei eventi dal 15 al 20 settembre, tre campagne a importo zero (lista, home,
mappa), trenta giorni di aperture e interazioni di eventi e profili, impression
e clic sponsorizzati web/Android. Su richiesta del proprietario i testi pubblici
non riportano etichette dimostrative. La provenienza resta documentata qui:
i numeri sono sintetici, non traffico reale e non ricavi. Non vengono creati
pagamenti, destinatari di marketing o prenotazioni reali.

## Remoto al deploy, solo quando richiesto

Dopo backup remoto e migrazioni, per ricreare lo stesso dataset senza dipendere
dagli ID locali:

```sh
php artisan analytics:demo padova --week=2026-09-14 --as-of=2026-09-15 --allow-production
```

Le chiavi stabili impediscono duplicati nella stessa settimana. I contatori
esistenti non vengono sovrascritti né incrementati nuovamente. La password
iniziale sul remoto sarà diversa: il comando la mostra solo se crea l’account.
Gli amministratori esistenti non sono modificati.

Questo ricrea i dati del seed, **non** eventuali modifiche fatte manualmente
nel pannello dopo il seeding. Per trasferire anche quelle occorrerà un export
mirato delle relazioni e dei media, con rimappatura degli ID, durante il deploy.
Non importare l’intero database locale sul remoto. Date e campagne restano
quelle indicate: per una dimostrazione futura scegliere esplicitamente una
nuova settimana. Non cancellare i dati demo tramite seeding generale/reset.
