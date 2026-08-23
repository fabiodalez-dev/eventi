<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Le liste dell'area personale che non hanno filtri propri — feed, follow,
 * archivio delle notifiche — e che quindi hanno in comune solo ciò che ogni
 * richiesta dell'API ha: quanti elementi, da quale cursore, con quali
 * inclusioni.
 */
class MeQueryRequest extends ApiRequest {}
