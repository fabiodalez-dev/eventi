<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\CollaboratorController;
use App\Http\Controllers\Api\V1\Management\VenueAccessController;
use Illuminate\Support\Facades\Route;

/*
 * Collaboratori, accesso e inviti (P8).
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Solo il locale: l'organizzazione non ha collaboratori né inviti (la pivot
 * `organizer_user` non ha ruoli, e il pannello `/organizza` non ha una
 * pagina per i membri). Sotto `organizers/{organizer}` queste rotte non
 * esistono, e rispondono 404.
 *
 * Invito e «Reinvia» non hanno un throttle di rotta: il tetto vero (10 l'ora,
 * 30 al giorno per persona e locale) è quello della pagina del pannello, con
 * le stesse chiavi (`VenueCollaborators::limitKeys`), e risponde 429.
 *
 * Gli inviti ricevuti (`/me/invitations`) stanno in
 * `routes/management/invitations.php`: sono della persona, non del locale.
 *
 * @var string $subjectType
 */
if ($subjectType === 'venue') {
    Route::get('/members', [CollaboratorController::class, 'index'])->name('members.index');
    Route::post('/members', [CollaboratorController::class, 'store'])
        ->middleware('throttle:30,1,management-members')->name('members.store');
    Route::post('/members/{member}/resend', [CollaboratorController::class, 'resend'])->whereNumber('member')
        ->middleware('throttle:30,1,management-members')->name('members.resend');
    Route::delete('/members/{member}/invitation', [CollaboratorController::class, 'withdraw'])->whereNumber('member')
        ->middleware('throttle:30,1,management-members')->name('members.withdraw');
    Route::delete('/members/{member}', [CollaboratorController::class, 'destroy'])->whereNumber('member')
        ->middleware('throttle:30,1,management-members')->name('members.destroy');

    Route::get('/access', [VenueAccessController::class, 'show'])->name('access.show');
    Route::post('/access/leave', [VenueAccessController::class, 'leave'])
        ->middleware('throttle:10,1,management-leave')->name('access.leave');
}
