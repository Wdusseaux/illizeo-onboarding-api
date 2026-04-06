<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    /**
     * Generate a new 2FA secret and return QR code URL.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        $secret = $google2fa->generateSecretKey();
        $user->update(['two_factor_secret' => $secret]);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'Illizeo'),
            $user->email,
            $secret
        );

        // Generate QR code as SVG using BaconQrCode
        $renderer = new \BaconQrCode\Renderer\Image\SvgImageBackEnd();
        $writer = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
                $renderer
            )
        );
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => base64_encode($qrCodeSvg),
            'qr_code_url' => $qrCodeUrl,
        ]);
    }

    /**
     * Confirm 2FA setup with a TOTP code.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $google2fa = new Google2FA();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => '2FA non initialisé'], 422);
        }

        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (!$valid) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        // Generate recovery codes
        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->toArray();

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ]);

        return response()->json([
            'message' => '2FA activé avec succès',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Verify a TOTP code (used during login).
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user || !$user->two_factor_enabled) {
            return response()->json(['message' => 'Utilisateur non trouvé ou 2FA non activé'], 422);
        }

        $google2fa = new Google2FA();

        // Try TOTP code first
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        // If not valid, try recovery codes
        if (!$valid && $user->two_factor_recovery_codes) {
            $codes = $user->two_factor_recovery_codes;
            $index = array_search($request->code, $codes);
            if ($index !== false) {
                $valid = true;
                // Remove used recovery code
                unset($codes[$index]);
                $user->update(['two_factor_recovery_codes' => array_values($codes)]);
            }
        }

        if (!$valid) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        // Create token
        $token = $user->createToken('2fa-verified', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            ],
            'token' => $token,
        ]);
    }

    /**
     * Disable 2FA.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        return response()->json(['message' => '2FA désactivé']);
    }

    /**
     * Get 2FA status.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) $user->two_factor_enabled,
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->toArray();
        $user->update(['two_factor_recovery_codes' => $recoveryCodes]);

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }
}
