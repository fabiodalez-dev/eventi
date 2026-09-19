<?php

declare(strict_types=1);

namespace App\Actions\Community;

use App\DTOs\PhoneRehashReport;
use App\Models\User;
use App\Models\WhatsappChallenge;
use App\Support\PhoneFingerprint;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ricalcola le impronte dei numeri WhatsApp con una chiave nuova (issue #103).
 *
 * Ogni riga viene classificata prima di scrivere alcunché: *already* se
 * l'impronta è già quella della chiave nuova, *rehash* se è quella della
 * vecchia, *mismatch* se non è né l'una né l'altra (chiave vecchia sbagliata o
 * riga corrotta), *orphan* per gli account sospesi e cancellati, che per
 * scelta conservano l'impronta senza il numero e quindi non si possono
 * ricalcolare. Basta un solo *mismatch*, una collisione o un'orfana non
 * autorizzata e non si scrive niente: una rotazione a metà lascerebbe impronte
 * con due chiavi diverse, cioè numeri già usati di nuovo liberi.
 *
 * Il lavoro sta sotto lo stesso lock della richiesta di codice e in una sola
 * transazione con le righe bloccate: nessun invio né conferma può infilarsi
 * fra la lettura e la scrittura.
 */
final class RehashPhoneFingerprints
{
    public function __invoke(
        #[\SensitiveParameter] string $newKey,
        #[\SensitiveParameter] string $oldKey,
        bool $dryRun = false,
        bool $releaseOrphans = false,
    ): PhoneRehashReport {
        if ($newKey === '' || $oldKey === '' || hash_equals($newKey, $oldKey)) {
            throw new \InvalidArgumentException('Both keys are required and must differ.');
        }

        return Cache::lock('community-whatsapp-requests', 300)->block(10, fn (): PhoneRehashReport => DB::transaction(
            fn (): PhoneRehashReport => $this->run($newKey, $oldKey, $dryRun, $releaseOrphans),
        ));
    }

    private function run(#[\SensitiveParameter] string $newKey, #[\SensitiveParameter] string $oldKey, bool $dryRun, bool $releaseOrphans): PhoneRehashReport
    {
        $users = ['already' => 0, 'rehash' => 0, 'mismatch' => 0, 'orphan' => 0];
        $challenges = ['already' => 0, 'rehash' => 0, 'mismatch' => 0];
        /** @var array<int, string> $userWrites id => impronta nuova */
        $userWrites = [];
        /** @var array<string, list<int>> $byFingerprint impronta nuova => utenti */
        $byFingerprint = [];
        $orphans = [];
        $orphanHashes = [];
        $mismatchedUsers = [];

        $rows = User::withTrashed()->whereNotNull('whatsapp_phone_hash')->orderBy('id')->lockForUpdate()
            ->get(['id', 'whatsapp_phone', 'whatsapp_phone_hash', 'community_suspended_at']);
        foreach ($rows as $user) {
            $stored = (string) $user->whatsapp_phone_hash;
            $phone = $this->phone(fn (): mixed => $user->whatsapp_phone);
            if ($phone === null) {
                // Senza numero l'impronta non si ricalcola: è quella di un sospeso cancellato,
                // oppure un numero illeggibile. Nel secondo caso non è un'orfana: non torna.
                if ($user->getRawOriginal('whatsapp_phone') === null) {
                    $users['orphan']++;
                    $orphans[] = (int) $user->id;
                    $orphanHashes[$stored] = true;
                } else {
                    $users['mismatch']++;
                    $mismatchedUsers[] = (int) $user->id;
                }

                continue;
            }
            $class = $this->classify($stored, $phone, $newKey, $oldKey);
            $users[$class]++;
            if ($class === 'mismatch') {
                $mismatchedUsers[] = (int) $user->id;

                continue;
            }
            $fresh = PhoneFingerprint::of($phone, $newKey);
            $byFingerprint[$fresh][] = (int) $user->id;
            if ($class === 'rehash') {
                $userWrites[(int) $user->id] = $fresh;
            }
        }

        // Due account sulla stessa impronta nuova, o un'impronta nuova uguale a quella di
        // un'orfana: l'indice unico fallirebbe a metà, o un numero bandito tornerebbe in uso.
        $colliding = [];
        foreach ($byFingerprint as $fingerprint => $ids) {
            if (count($ids) > 1 || isset($orphanHashes[$fingerprint])) {
                array_push($colliding, ...$ids);
            }
        }

        $challengeWrites = [];
        $mismatchedChallenges = [];
        foreach (WhatsappChallenge::query()->orderBy('id')->lockForUpdate()->get(['id', 'phone', 'phone_hash']) as $challenge) {
            $phone = $this->phone(fn (): mixed => $challenge->phone);
            $class = $phone === null ? 'mismatch' : $this->classify((string) $challenge->phone_hash, $phone, $newKey, $oldKey);
            $challenges[$class]++;
            if ($class === 'mismatch') {
                $mismatchedChallenges[] = (string) $challenge->id;
            } elseif ($class === 'rehash' && $phone !== null) {
                $challengeWrites[(string) $challenge->id] = PhoneFingerprint::of($phone, $newKey);
            }
        }

        $report = new PhoneRehashReport(
            $users, $challenges, $mismatchedUsers, $mismatchedChallenges, $colliding,
            orphansBlocking: $orphans !== [] && ! $releaseOrphans,
            written: false,
        );
        if ($dryRun || $report->aborted()) {
            return $report;
        }

        // Scritture dirette: niente eventi dei modelli e nessun updated_at che cambia per una chiave.
        foreach ($userWrites as $id => $fingerprint) {
            DB::table('users')->where('id', $id)->update(['whatsapp_phone_hash' => $fingerprint]);
        }
        // L'impronta di un'orfana non potrà mai più coincidere: tenerla ingannerebbe soltanto.
        // La data della sospensione resta, a documentare perché l'account era stato chiuso.
        if ($orphans !== []) {
            DB::table('users')->whereIn('id', $orphans)->update(['whatsapp_phone_hash' => null]);
        }
        foreach ($challengeWrites as $id => $fingerprint) {
            DB::table('whatsapp_challenges')->where('id', $id)->update(['phone_hash' => $fingerprint]);
        }

        return new PhoneRehashReport(
            $users, $challenges, [], [], [], orphansBlocking: false,
            written: $userWrites !== [] || $orphans !== [] || $challengeWrites !== [],
        );
    }

    /** @return 'already'|'rehash'|'mismatch' */
    private function classify(string $stored, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $newKey, #[\SensitiveParameter] string $oldKey): string
    {
        return match (true) {
            hash_equals(PhoneFingerprint::of($phone, $newKey), $stored) => 'already',
            hash_equals(PhoneFingerprint::of($phone, $oldKey), $stored) => 'rehash',
            default => 'mismatch',
        };
    }

    /**
     * Il numero in chiaro, o null se manca o non si decifra (APP_KEY cambiata, riga corrotta).
     *
     * @param  \Closure(): mixed  $read
     */
    private function phone(\Closure $read): ?string
    {
        try {
            $phone = $read();
        } catch (DecryptException) {
            return null;
        }

        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
