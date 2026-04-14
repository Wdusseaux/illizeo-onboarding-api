<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NotDisposableEmail implements ValidationRule
{
    /**
     * Known disposable email domains (subset — full list fetched from GitHub).
     */
    private const KNOWN_DISPOSABLE = [
        'yopmail.com', 'yopmail.fr', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.net',
        'tempmail.com', 'temp-mail.org', 'throwaway.email', 'mailinator.com', 'maildrop.cc',
        'dispostable.com', 'trashmail.com', 'trashmail.me', 'trashmail.net', 'sharklasers.com',
        'guerrillamailblock.com', 'grr.la', 'tempail.com', 'fakeinbox.com', 'mailnesia.com',
        'tempinbox.com', 'burpcollaborator.net', 'jetable.org', 'mytemp.email', 'mohmal.com',
        'getnada.com', 'emailondeck.com', 'tempr.email', 'discard.email', 'discardmail.com',
        'discardmail.de', 'harakirimail.com', 'mailexpire.com', 'tempmailer.com', 'throwam.com',
        'tempmailaddress.com', 'crazymailing.com', 'inboxbear.com', 'minutemailbox.com',
        '10minutemail.com', '10minutemail.net', '10minutemail.org', '10minutemail.de',
        'mailcatch.com', 'mailnator.com', 'mailsac.com', 'spamgourmet.com', 'guerrillamail.info',
        'mintemail.com', 'mailscrap.com', 'disposable.ml', 'getairmail.com', 'meltmail.com',
        'tempmailo.com', 'emailfake.com', 'generator.email', 'fakemailgenerator.com',
        'temporaryemail.net', 'temp-mail.io', 'tempail.com', 'tmpmail.org', 'tmpmail.net',
        'burnermail.io', 'mailpoof.com', 'filzmail.com', 'nomail.xl.cx', 'despammed.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value || !str_contains($value, '@')) {
            return;
        }

        $domain = strtolower(trim(explode('@', $value)[1]));

        // 1. Check against known list
        if (in_array($domain, self::KNOWN_DISPOSABLE, true)) {
            $fail($this->message());
            return;
        }

        // 2. Check against full GitHub list (cached 24h)
        $fullList = $this->getFullList();
        if (in_array($domain, $fullList, true)) {
            $fail($this->message());
            return;
        }

        // 3. Check MX records — domain must have valid MX
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            $fail(__('Cette adresse email semble invalide (domaine sans serveur mail).'));
            return;
        }
    }

    private function getFullList(): array
    {
        return Cache::remember('disposable_email_domains', 86400, function () {
            try {
                $response = Http::timeout(5)->get(
                    'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf'
                );

                if ($response->successful()) {
                    return array_filter(
                        array_map('trim', explode("\n", $response->body())),
                        fn ($line) => $line && !str_starts_with($line, '#')
                    );
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch disposable email list: ' . $e->getMessage());
            }

            return self::KNOWN_DISPOSABLE;
        });
    }

    private function message(): string
    {
        return __('Les adresses email temporaires ou jetables ne sont pas autorisées.');
    }
}
