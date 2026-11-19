<?php

declare(strict_types=1);

namespace App\Services\Community;

/**
 * Le schede della community nella testata delle sue pagine.
 *
 * Bacheca, Persone, Profilo pubblico e Avvisi sono quattro pagine dello stesso
 * posto: le schede stanno sempre nello stesso punto, sopra il titolo, e dicono
 * dove si è. Le pagine che non hanno una scheda propria accendono quella a cui
 * appartengono: il profilo di un'altra persona e «Seguiti e follower» stanno
 * sotto «Persone», il proprio profilo sotto «Profilo pubblico».
 */
final class CommunityNavigation
{
    private const TABS = [
        'feed' => 'community.feed',
        'people' => 'community.people',
        'settings' => 'community.settings',
        'inbox' => 'community.inbox',
    ];

    /**
     * @param  string|null  $active  la scheda da accendere (`feed`, `people`, `settings`, `inbox`); per difetto quella della rotta corrente
     * @return list<array{href: string, label: string, active: bool}>
     */
    public static function tabs(?string $active = null): array
    {
        $tabs = [];
        foreach (self::TABS as $key => $route) {
            $tabs[] = [
                'href' => route($route),
                'label' => __('community.tabs.'.$key),
                'active' => $active !== null ? $active === $key : request()->routeIs($route),
            ];
        }

        return $tabs;
    }
}
