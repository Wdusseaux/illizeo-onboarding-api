<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('roles')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'roles' => $u->getRoleNames(),
            'role' => $u->getRoleNames()->first() ?? '',
            'created_at' => $u->created_at,
            'email_verified_at' => $u->email_verified_at,
        ]);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,admin,admin_rh,manager,onboardee',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        // Send invitation email
        try {
            $firstName = explode(' ', $validated['name'])[0];
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $tenantId = tenant('id');

            // Try to use the "Invitation onboarding" template if it exists
            $template = EmailTemplate::where('nom', 'Invitation onboarding')->where('actif', true)->first();

            $subject = $template
                ? str_replace(['{{prenom}}'], [$firstName], $template->sujet)
                : "Bienvenue — Votre compte Illizeo a été créé";

            $body = $template && $template->contenu
                ? str_replace(
                    ['{{prenom}}', '{{nom}}', '{{email}}', '{{site}}', '{{poste}}', '{{lien}}'],
                    [$firstName, $validated['name'], $validated['email'], '', '', "{$frontendUrl}?tenant={$tenantId}"],
                    $template->contenu
                )
                : "Bonjour {$firstName},\n\nVotre compte Illizeo a été créé.\n\nConnectez-vous avec votre email ({$validated['email']}) sur :\n{$frontendUrl}?tenant={$tenantId}\n\nMot de passe temporaire : merci de contacter votre administrateur.\n\nCordialement,\nL'équipe Illizeo";

            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#f5f5fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
  <tr><td style="background:#C2185B;padding:20px 24px;text-align:center;">
    <span style="color:#fff;font-size:22px;font-weight:700;letter-spacing:1px;">ILLIZEO</span>
  </td></tr>
  <tr><td style="padding:32px;">
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:24px;">{$subject}</div>
    <div style="font-size:14px;line-height:1.7;color:#333;">{$body}</div>
  </td></tr>
  <tr><td style="padding:16px 32px;border-top:1px solid #E8E8EE;">
    <table width="100%"><tr><td style="text-align:center;">
      <a href="{$frontendUrl}?tenant={$tenantId}" style="display:inline-block;padding:10px 28px;background:#C2185B;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Accéder à Illizeo</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#f5f5fa;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #E8E8EE;">
    Cet email a été envoyé automatiquement par Illizeo.
  </td></tr>
</table>
</td></tr></table></body></html>
HTML;

            Mail::html($html, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
            });
        } catch (\Exception $e) {
            \Log::warning("Invitation email failed for {$user->email}: " . $e->getMessage());
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|in:super_admin,admin,admin_rh,manager,onboardee',
        ]);

        if (isset($validated['name'])) $user->name = $validated['name'];
        if (isset($validated['email'])) $user->email = $validated['email'];
        if (!empty($validated['password'])) $user->password = Hash::make($validated['password']);
        $user->save();

        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Invite a user — creates an account with an unguessable random password
     * (so they cannot log in until they set their own) and emails a "set your
     * password" link backed by the existing password_reset_tokens infra.
     *
     * Used by the setup wizard team step and any "Inviter un collaborateur"
     * action where the admin shouldn't have to choose a temp password.
     */
    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:super_admin,admin,admin_rh,manager,onboardee',
        ]);

        // Random password the user will never need to know — they'll set their
        // own via the reset link in the invitation email.
        $randomPassword = Str::random(40);
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($randomPassword),
        ]);
        $user->assignRole($validated['role']);

        // Issue a single-use signup token via the existing password_reset_tokens
        // table. We use a longer-lived token here (the reset flow accepts up to
        // 60min — admins can re-invite if needed past that).
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $validated['email']],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $tenantId = tenant('id');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $signupUrl = "{$frontendUrl}?tenant={$tenantId}&reset_token={$token}&email=" . urlencode($validated['email']);
        $firstName = explode(' ', trim($validated['name']))[0];
        $tenantName = \App\Models\CompanySetting::get('company_name') ?: ($tenantId ? ucfirst($tenantId) : 'Illizeo');

        try {
            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#f5f5fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
  <tr><td style="background:#E41076;padding:20px 24px;text-align:center;">
    <span style="color:#fff;font-size:22px;font-weight:700;letter-spacing:1px;">ILLIZEO</span>
  </td></tr>
  <tr><td style="padding:32px;">
    <h2 style="margin:0 0 16px;color:#333;font-size:18px;">Bienvenue chez {$tenantName}, {$firstName} !</h2>
    <p style="font-size:14px;line-height:1.7;color:#333;">Un espace Illizeo a été créé pour vous. Cliquez sur le bouton ci-dessous pour choisir votre mot de passe et activer votre compte.</p>
    <table width="100%"><tr><td align="center" style="padding:24px 0;">
      <a href="{$signupUrl}" style="display:inline-block;padding:12px 32px;background:#E41076;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Activer mon compte</a>
    </td></tr></table>
    <p style="font-size:12px;color:#888;">Ce lien d'activation est valable 1 heure. Si vous n'arrivez pas à cliquer, copiez ce lien dans votre navigateur :<br><span style="word-break:break-all;color:#aaa;">{$signupUrl}</span></p>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#f5f5fa;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #E8E8EE;">
    Cet email a été envoyé automatiquement par Illizeo.
  </td></tr>
</table>
</td></tr></table></body></html>
HTML;
            Mail::html($html, function ($m) use ($user, $tenantName) {
                $m->to($user->email)->subject("Bienvenue chez {$tenantName} — Activez votre compte Illizeo");
            });
        } catch (\Exception $e) {
            Log::warning("Invite email failed for {$user->email}: " . $e->getMessage());
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
            'invited' => true,
        ], 201);
    }
}
