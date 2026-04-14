<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Build dynamic password validation rules from company settings.
     */
    public static function getPasswordRules(): array
    {
        $raw = CompanySetting::get('password_policy');
        $policy = is_string($raw) ? json_decode($raw, true) : ($raw ?? []);

        $rules = ['required', 'string', 'min:' . ($policy['min_length'] ?? 8)];

        if ($policy['uppercase'] ?? true) {
            $rules[] = 'regex:/[A-Z]/';
        }
        if ($policy['lowercase'] ?? true) {
            $rules[] = 'regex:/[a-z]/';
        }
        if ($policy['number'] ?? true) {
            $rules[] = 'regex:/[0-9]/';
        }
        if ($policy['special'] ?? false) {
            $rules[] = 'regex:/[!@#$%^&*(),.?":{}|<>]/';
        }

        // Check against Have I Been Pwned API (k-anonymity — safe, no full password sent)
        if ($policy['no_common'] ?? true) {
            $rules[] = Password::min(1)->uncompromised(3); // reject if seen 3+ times in breaches
        }

        return $rules;
    }

    /**
     * Return the current password policy (public, no auth).
     */
    public static function getPolicy(): JsonResponse
    {
        $raw = CompanySetting::get('password_policy');
        $policy = is_string($raw) ? json_decode($raw, true) : ($raw ?? []);

        $defaults = [
            'min_length' => 8,
            'uppercase' => true,
            'lowercase' => true,
            'number' => true,
            'special' => false,
            'no_common' => true,
            'no_name' => false,
            'max_attempts' => 5,
            'history_count' => 3,
            'expiry_days' => 0,
        ];

        return response()->json(array_merge($defaults, $policy));
    }

    /**
     * Request password reset — generates a token
     * In production, this would send an email. For now, returns the token.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            // Don't reveal if email exists
            return response()->json(['message' => 'Si cette adresse existe, un email de réinitialisation a été envoyé.']);
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = env('FRONTEND_URL', 'http://localhost:3000') . "?reset_token={$token}&email=" . urlencode($request->email);

        // Send reset email
        try {
            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#f5f5fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
  <tr><td style="background:#C2185B;padding:20px 24px;text-align:center;">
    <span style="color:#fff;font-size:22px;font-weight:700;letter-spacing:1px;">ILLIZEO</span>
  </td></tr>
  <tr><td style="padding:32px;">
    <h2 style="margin:0 0 16px;color:#333;">Réinitialisation de votre mot de passe</h2>
    <p style="font-size:14px;line-height:1.7;color:#333;">Bonjour,</p>
    <p style="font-size:14px;line-height:1.7;color:#333;">Vous avez demandé la réinitialisation de votre mot de passe Illizeo. Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe :</p>
    <table width="100%"><tr><td align="center" style="padding:24px 0;">
      <a href="{$resetUrl}" style="display:inline-block;padding:12px 32px;background:#C2185B;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Réinitialiser mon mot de passe</a>
    </td></tr></table>
    <p style="font-size:12px;color:#888;">Ce lien est valable pendant 1 heure. Si vous n'avez pas demandé cette réinitialisation, ignorez cet email.</p>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#f5f5fa;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #E8E8EE;">
    Cet email a été envoyé automatiquement par Illizeo.
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;

            Mail::html($html, function ($message) use ($user, $request) {
                $message->to($user->email)->subject('Réinitialisation de votre mot de passe Illizeo');
            });
        } catch (\Exception $e) {
            \Log::warning("Password reset email failed: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Si cette adresse existe, un email de réinitialisation a été envoyé.',
        ]);
    }

    /**
     * Reset password with token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $pwdRules = array_merge(self::getPasswordRules(), ['confirmed']);
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => $pwdRules,
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'Token invalide ou expiré.'], 422);
        }

        // Check token age (1 hour max)
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'Le lien a expiré. Veuillez refaire une demande.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    /**
     * Change own password (authenticated user)
     */
    public function changePassword(Request $request): JsonResponse
    {
        $pwdRules = array_merge(self::getPasswordRules(), ['confirmed']);
        $request->validate([
            'current_password' => 'required|string',
            'password' => $pwdRules,
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    /**
     * Admin resets a user's password (no current password needed)
     */
    public function adminResetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => self::getPasswordRules(),
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => "Mot de passe de {$user->name} réinitialisé."]);
    }
}
