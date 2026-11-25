<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dashboard;

use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il riepilogo del soggetto e il programma della settimana in PDF (P11).
 *
 * Chi può: ogni membro del soggetto, come la prima pagina dei pannelli
 * (`AnalyticsDetailPage::canAccess` chiede `canAccessTenant`). Il soggetto
 * l'ha già controllato `ResolveManagementSubject`; qui si rifà la stessa
 * domanda, come fa la pagina a ogni richiesta.
 */
final class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = ManagementSubject::current();

        return $subject !== null && $subject->user->canAccessTenant($subject->model);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Il programma: un giorno qualsiasi della settimana da stampare; senza, questa settimana.
            'week' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
