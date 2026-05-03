<?php

namespace App\Mail\Concerns;

/**
 * Trait — embeds the Illizeo logo as an inline (CID) attachment.
 *
 * This is the email industry standard for inline images. The logo is
 * attached to the email with a Content-ID, and referenced in the HTML
 * via `<img src="cid:illizeo-logo">`. Works in 100% of email clients
 * (Outlook desktop & web, Gmail, Apple Mail, Yahoo, mobile clients).
 *
 * Avoids :
 *  - Base64 data URIs (broken in Outlook desktop > ~10 KB)
 *  - Remote URLs (blocked by email proxies due to server CORP headers)
 *
 * Usage in a Mailable :
 *   1. Add `use HasIllizeoLogo;`
 *   2. Call `$this->embedIllizeoLogo();` in the constructor
 *   3. Reference in HTML : <img src="cid:illizeo-logo">
 */
trait HasIllizeoLogo
{
    /**
     * Returns the cid: reference to use in <img src="...">.
     */
    public function illizeoLogoSrc(): string
    {
        return 'cid:illizeo-logo';
    }

    /**
     * Register the inline logo attachment on the underlying Symfony Email.
     * Call this from the Mailable's constructor (or anywhere before send).
     */
    protected function embedIllizeoLogo(): void
    {
        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $email): void {
            $logoPath = public_path('build/illizeo-Logo-site.png');
            if (!file_exists($logoPath)) return;
            // embedFromPath automatically sets Content-Disposition: inline
            // and uses the provided name as the Content-ID
            $email->embedFromPath($logoPath, 'illizeo-logo', 'image/png');
        });
    }
}
