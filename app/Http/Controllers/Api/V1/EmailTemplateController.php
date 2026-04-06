<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(EmailTemplate::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'sujet' => 'required|string',
            'declencheur' => 'required|string',
            'variables' => 'nullable|array',
            'actif' => 'nullable|boolean',
            'contenu' => 'nullable|string',
        ]);

        $template = EmailTemplate::create($validated);
        return response()->json($template, 201);
    }

    public function show(EmailTemplate $emailTemplate): JsonResponse
    {
        return response()->json($emailTemplate);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'sujet' => 'sometimes|string',
            'declencheur' => 'sometimes|string',
            'variables' => 'nullable|array',
            'actif' => 'nullable|boolean',
            'contenu' => 'nullable|string',
        ]));

        return response()->json($emailTemplate);
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->delete();
        return response()->json(null, 204);
    }

    public function duplicate(EmailTemplate $emailTemplate): JsonResponse
    {
        $copy = $emailTemplate->replicate();
        $copy->nom = $copy->nom . ' (copie)';
        $copy->save();
        return response()->json($copy, 201);
    }

    public function sendTest(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $variables = [
            '{{prenom}}' => 'Jean', '{{nom}}' => 'Dupont', '{{email}}' => 'jean.dupont@exemple.com',
            '{{date_debut}}' => '01/06/2026', '{{site}}' => 'Genève', '{{poste}}' => 'Chef de Projet',
            '{{departement}}' => 'Tech', '{{parcours_nom}}' => 'Onboarding Standard',
            '{{nb_docs_manquants}}' => '3', '{{date_limite}}' => '15/06/2026',
            '{{manager}}' => 'Mehdi Kessler', '{{adresse}}' => 'Rue du Marché 10, Genève',
            '{{lien}}' => env('FRONTEND_URL', 'http://localhost:3000'),
            '{{action_nom}}' => 'Signer le contrat', '{{document_nom}}' => 'Pièce d\'identité',
            '{{collab_nom}}' => 'Jean Dupont', '{{montant}}' => '500 CHF', '{{annees}}' => '1',
            '{{date_depart}}' => '15/12/2026', '{{date_fin_essai}}' => '01/09/2026',
            '{{candidat_nom}}' => 'Sophie Martin', '{{formulaire_nom}}' => 'Questionnaire d\'intégration',
        ];

        $subject = strtr($emailTemplate->sujet, $variables);
        $body = strtr($emailTemplate->contenu ?: 'Pas de contenu défini pour ce template.', $variables);

        $themeColor = \App\Models\CompanySetting::get('theme_color', '#C2185B');
        $html = \App\Services\WorkflowEngine::buildHtmlEmail($subject, $body, $themeColor);

        try {
            Mail::html($html, function ($message) use ($request, $subject) {
                $message->to($request->email)->subject('[TEST] ' . $subject);
            });
            return response()->json(['success' => true, 'message' => "Email test envoyé à {$request->email}"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function getMailConfig(): JsonResponse
    {
        $fromAddress = \App\Models\CompanySetting::get('mail_from_address', env('MAIL_FROM_ADDRESS', 'no-reply@illizeo.com'));
        $fromName = \App\Models\CompanySetting::get('mail_from_name', env('MAIL_FROM_NAME', 'Illizeo'));

        return response()->json([
            'single_recipient' => env('MAIL_SINGLE_RECIPIENT', ''),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'mailer' => env('MAIL_MAILER', 'log'),
        ]);
    }
}
