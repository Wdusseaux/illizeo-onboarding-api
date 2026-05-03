<?php

namespace App\Mail\Concerns;

/**
 * Helper trait — returns the Illizeo logo as a base64 data URI.
 *
 * Why not a remote URL?
 *  - Email clients (especially Outlook) block remote images by default.
 *  - Production server sets `Cross-Origin-Resource-Policy: same-origin`,
 *    which prevents Outlook's image proxy from caching the asset.
 *  - Inline base64 is universally compatible across all major mail clients.
 *
 * Cost: ~73 KB added to each email (vs. 55 KB original PNG).
 * Acceptable for transactional emails.
 */
trait HasIllizeoLogo
{
    /**
     * Returns the public URL of the Illizeo logo for use in emails.
     *
     * We use a dedicated /email-assets/illizeo-logo.png endpoint that
     * explicitly sets Cross-Origin-Resource-Policy: cross-origin so that
     * Outlook & Gmail image proxies can cache the image. The default
     * /build/* assets have CORP: same-origin which blocks email proxies.
     *
     * Avoid base64 data URIs : Outlook desktop fails to render them when
     * larger than ~10 KB.
     */
    public function illizeoLogoSrc(): string
    {
        $appUrl = rtrim(config('app.frontend_url') ?: env('FRONTEND_URL', 'https://onboarding.illizeo.com'), '/');
        return "{$appUrl}/email-assets/illizeo-logo.png";
    }
}
