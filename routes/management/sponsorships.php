<?php

declare(strict_types=1);

/*
 * Sponsorizzazioni (P6).
 *
 * Vuoto finché il pacchetto non arriva. Incluso due volte da `routes/api.php`,
 * dentro `venues/{venue}` e dentro `organizers/{organizer}`: `$subjectType`
 * (`venue` o `organizer`) dice in quale dei due, per le rotte di un solo
 * soggetto. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * @var string $subjectType
 */
