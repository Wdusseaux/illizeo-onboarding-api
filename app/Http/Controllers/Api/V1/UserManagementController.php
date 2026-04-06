<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
}
