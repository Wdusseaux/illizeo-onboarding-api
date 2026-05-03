<?php

namespace App\Mail\Support;

use App\Models\CompanySetting;
use Symfony\Component\Mime\Email;

/**
 * Helper that embeds the inline logo (CID) on a raw Symfony Email.
 *
 * Use this from non-Mailable email paths (Mail::html(), workflow engine, etc.)
 * where the HasIllizeoLogo trait can't be applied. Mailables should keep using
 * the trait directly.
 *
 * Tenant→employee emails (workflow templates, notifications) pass
 * useTenantLogo=true to fall back to the Illizeo logo only when no custom logo
 * is defined. Platform→tenant emails pass false (or don't call this helper).
 */
class TenantLogoEmbedder
{
    public const CID_NAME = 'illizeo-logo';

    /**
     * Embed the inline logo on a Symfony Email and return the cid: reference
     * to use as the <img src="..."> value.
     */
    public static function embed(Email $email, bool $useTenantLogo = false): string
    {
        if ($useTenantLogo && self::tryEmbedTenantLogo($email)) {
            return 'cid:' . self::CID_NAME;
        }

        $path = public_path('build/illizeo-Logo-site.png');
        if (file_exists($path)) {
            $email->embedFromPath($path, self::CID_NAME, 'image/png');
        }

        return 'cid:' . self::CID_NAME;
    }

    private static function tryEmbedTenantLogo(Email $email): bool
    {
        try {
            $custom = CompanySetting::where('key', 'custom_logo_full')->value('value');
        } catch (\Throwable) {
            return false;
        }
        if (!$custom || !is_string($custom) || !str_starts_with($custom, 'data:image/')) {
            return false;
        }
        $parts = explode(',', $custom, 2);
        if (count($parts) !== 2) return false;
        [$meta, $b64] = $parts;
        $mime = 'image/png';
        if (preg_match('#data:(image/[a-z0-9.+\-]+);base64#i', $meta, $m)) {
            $mime = strtolower($m[1]);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) < 100) {
            return false;
        }
        $email->embed($bin, self::CID_NAME, $mime);
        return true;
    }
}
