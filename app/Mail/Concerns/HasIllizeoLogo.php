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
        return 'cid:' . \App\Mail\Support\TenantLogoEmbedder::CID_NAME;
    }

    /**
     * Register the inline logo attachment on the underlying Symfony Email.
     * Call this from the Mailable's constructor (or anywhere before send).
     *
     * When $useTenantLogo is true, the tenant's custom logo (CompanySetting
     * key "custom_logo_full") is used if defined; otherwise the Illizeo logo
     * is used as fallback. Tenant logo only applies for tenant→employee
     * mailables (NotificationMail). Platform→tenant mailables (Invoice,
     * Welcome, Dunning, WeeklyAiSummary) always keep the Illizeo logo.
     */
    protected function embedIllizeoLogo(bool $useTenantLogo = false): void
    {
        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $email) use ($useTenantLogo): void {
            \App\Mail\Support\TenantLogoEmbedder::embed($email, $useTenantLogo);
        });
    }
}
