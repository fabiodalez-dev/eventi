<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Models\EventComment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** Local checks shared by web and API. Never send or log the submitted text. */
final class CheckCommentContent
{
    public function handle(string $body, User $user): void
    {
        $text = $this->normalize($body);
        $emailText = preg_replace('/\s*(?:\[at\]|\(at\)|\[chiocciola\]|\(chiocciola\))\s*/iu', '@', $text) ?? $text;
        $emailText = preg_replace('/\s*(?:\[dot\]|\(dot\)|\[punto\]|\(punto\))\s*/iu', '.', $emailText) ?? $emailText;
        $personalPatterns = [
            '/[\pL\pN.!#$%&\x27*+\/=?^_`{|}~-]+\s*@\s*[\pL\pN-]+(?:\s*\.\s*[\pL\pN-]+)+/u',
            '/(?<![\pL\pN])(?:\+|00)[1-9](?:[\s().-]*\d){7,14}(?!\d)/u',
            '/(?<![\pL\pN])(?:3\d{2}|0[1-9]\d{0,3})(?:[\s().-]*\d){6,8}(?!\d)/u',
            '/\b[A-Z]{6}\d{2}[ABCDEHLMPRST](?:[0-7]\d)[A-Z]\d{3}[A-Z]\b/i',
            '/\b(?:abito|vivo|residente|domicilio|indirizzo\s+(?:di\s+casa|privato))\b.{0,45}\b(?:via|viale|piazza|vicolo|corso)\b.{1,65}\d/iu',
            '/\b(?:password|passwd|api[_ -]?key|access[_ -]?token)\s*[:=]\s*\S{6,}/iu',
        ];
        foreach ($personalPatterns as $pattern) {
            if (preg_match($pattern, $emailText)) {
                $this->reject('personal_data');
            }
        }
        // Match compact IBANs and the usual groups of four; validate the checksum.
        if (preg_match_all('/\b[a-z]{2}\d{2}(?:[a-z0-9]{11,30}|(?: [a-z0-9]{4}){2,7}(?: [a-z0-9]{1,4})?)\b/i', $text, $ibans)) {
            foreach ($ibans[0] as $iban) {
                if ($this->validIban(str_replace(' ', '', $iban))) {
                    $this->reject('personal_data');
                }
            }
        }
        if (preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                if ($this->luhn(preg_replace('/\D/', '', $candidate) ?? '')) {
                    $this->reject('personal_data');
                }
            }
        }
        if (preg_match_all('~(?:https?://|www\.)[^\s<>]+~iu', $text) > 2
            || preg_match('/\b(?:guadagni? garantiti|raddoppia i tuoi soldi|compra follower|casino online)\b/iu', $text)
            || preg_match('/(.)\1{19,}/u', $text)) {
            $this->reject('spam');
        }
        // Bound the scan; the write route also limits each account to ten posts/hour.
        $recent = EventComment::query()->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())->latest('id')->limit(30)->pluck('body');
        foreach ($recent as $previous) {
            if ($this->normalize($previous) === $text) {
                $this->reject('duplicate');
            }
        }
    }

    public function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = preg_replace('/[\p{Cf}\p{Mn}]/u', '', $text) ?? $text;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function validIban(string $iban): bool
    {
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        $remainder = 0;
        foreach (str_split(strtoupper(substr($iban, 4).substr($iban, 0, 4))) as $character) {
            foreach (str_split(ctype_alpha($character) ? (string) (ord($character) - 55) : $character) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    private function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $digit) {
            $n = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['body' => __('comments.filter.'.$reason)]);
    }
}
