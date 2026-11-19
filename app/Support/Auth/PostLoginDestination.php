<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Throwable;

/**
 * Dove arriva una persona appena entrata dal login unico (`/accedi`).
 *
 * Una porta sola per tutti, redazione compresa: chi entra va nella propria
 * area — la redazione in `/admin`, chi gestisce un locale nel pannello del
 * locale, chi gestisce un organizzatore nel suo, tutti gli altri nel feed.
 * Se era partito da una pagina precisa (`url.intended`), torna lì: quella
 * pagina vince sull'area, ma solo se è di questo sito e se la persona può
 * aprirla. Un pannello chiuso o il locale di un altro non diventano un 403
 * appena entrati: si scartano e si va nella propria area.
 *
 * Lo usano l'accesso con password, il collegamento di accesso e il
 * middleware `guest` (`redirectUsersTo`), che manda qui chi apre `/accedi`
 * quando è già dentro.
 */
final class PostLoginDestination
{
    /** L'area della persona, senza guardare da dove arrivava. */
    public static function for(User $user): string
    {
        $admin = self::panel('admin');
        if ($admin !== null && $user->canAccessPanel($admin)) {
            return $admin->getUrl() ?? url('/'.$admin->getPath());
        }

        $venuePanel = self::panel('venue');
        if ($venuePanel !== null && $user->canAccessPanel($venuePanel)) {
            $venue = $user->getTenants($venuePanel)->first();
            if ($venue !== null) {
                return $venuePanel->getUrl($venue) ?? url('/'.$venuePanel->getPath());
            }
        }

        $organizerPanel = self::panel('organizer');
        if ($organizerPanel !== null && $user->canAccessPanel($organizerPanel)) {
            $organizer = $user->getTenants($organizerPanel)->first();
            if ($organizer !== null) {
                return $organizerPanel->getUrl($organizer) ?? url('/'.$organizerPanel->getPath());
            }
        }

        return route('account.feed');
    }

    /**
     * La pagina da cui la persona era partita, se la può aprire; altrimenti
     * la sua area.
     */
    public static function resolve(User $user, mixed $intended): string
    {
        return is_string($intended) && self::allows($user, $intended) ? $intended : self::for($user);
    }

    /**
     * Per chi apre `/accedi` (o il collegamento) quando è già dentro: vale il
     * ritorno scritto nell'indirizzo, poi l'area.
     */
    public static function forRequest(Request $request): string
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return route('home');
        }

        return self::resolve($user, $request->query('intended'));
    }

    /**
     * Un indirizzo di ritorno accettabile: di questo sito (assoluto sullo
     * stesso host, o un percorso che comincia con una sola barra), senza
     * caratteri di controllo, e — se porta in un pannello — un pannello e un
     * locale o organizzatore che la persona può aprire.
     */
    public static function allows(User $user, string $url): bool
    {
        $path = self::sameSitePath($url);
        if ($path === null) {
            return false;
        }

        $panel = self::panelFor($path);
        if ($panel === null) {
            return true;
        }

        if (! $user->canAccessPanel($panel)) {
            return false;
        }

        if (! $panel->hasTenancy()) {
            return true;
        }

        $segments = explode('/', trim($path, '/'));
        $slug = $segments[1] ?? null;
        if ($slug === null || $slug === '') {
            return true;
        }

        try {
            return $user->canAccessTenant($panel->getTenant($slug));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Il pannello che un indirizzo apre, se ne apre uno. Serve anche alla
     * pagina di accesso per dire dove si sta entrando.
     */
    public static function panelFor(string $url): ?Panel
    {
        $path = self::sameSitePath($url) ?? '';
        $path = '/'.ltrim($path, '/');

        foreach (Filament::getPanels() as $panel) {
            $base = '/'.trim($panel->getPath(), '/');
            if ($path === $base || str_starts_with($path, $base.'/')) {
                return $panel;
            }
        }

        return null;
    }

    /** Il percorso di un indirizzo di questo sito, o `null` se porta fuori. */
    private static function sameSitePath(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\\\\\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        $root = rtrim(request()->getSchemeAndHttpHost(), '/');
        $app = rtrim(url('/'), '/');
        foreach (array_unique([$root, $app]) as $origin) {
            if ($url === $origin || str_starts_with($url, $origin.'/') || str_starts_with($url, $origin.'?')) {
                return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            }
        }

        return null;
    }

    private static function panel(string $id): ?Panel
    {
        try {
            return Filament::getPanel($id, isStrict: true);
        } catch (Throwable) {
            return null;
        }
    }
}
