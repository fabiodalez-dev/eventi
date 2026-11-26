<?php

declare(strict_types=1);

/*
 * R2 — Eventi: elenco, moderazione, proposte (R-EV-01…04, 07…17, 25, R-SB-01…05, R-WB-01).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: events, submissions.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */
