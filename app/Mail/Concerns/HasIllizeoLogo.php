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
    private static ?string $_cachedLogoSrc = null;

    /**
     * Returns the logo as <img> src — base64 data URI, or remote fallback.
     */
    public function illizeoLogoSrc(): string
    {
        if (self::$_cachedLogoSrc !== null) {
            return self::$_cachedLogoSrc;
        }

        $logoPath = public_path('build/illizeo-Logo-site.png');
        if (file_exists($logoPath)) {
            $contents = file_get_contents($logoPath);
            if ($contents !== false) {
                self::$_cachedLogoSrc = 'data:image/png;base64,' . base64_encode($contents);
                return self::$_cachedLogoSrc;
            }
        }

        // Fallback to remote URL (unlikely to load in Outlook but better than nothing)
        self::$_cachedLogoSrc = 'https://onboarding.illizeo.com/build/illizeo-Logo-site.png';
        return self::$_cachedLogoSrc;
    }
}
