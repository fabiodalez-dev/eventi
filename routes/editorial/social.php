<?php

declare(strict_types=1);

/*
 * R9 — Social della redazione (R-SO-01…07, R-DB-10, R-WB-03, R-WB-04).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: social.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */
