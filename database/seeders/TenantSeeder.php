<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // Roles & permissions (always needed)
        $this->call(RolesAndPermissionsSeeder::class);

        // Default business data (parcours, phases, actions, groups, etc.) for ALL tenants
        $this->call(DefaultDataSeeder::class);

        // Full demo data (collaborateurs, NPS, cooptation, messages, etc.) only for 'illizeo'
        $tenantId = tenant('id');
        if ($tenantId === 'illizeo') {
            $this->call(IllizeoSeeder::class);
        }

        // Default company settings (appearance & locale)
        $defaults = [
            'theme_color' => '#C2185B',
            'custom_logo' => '',
            'custom_logo_full' => '',
            'custom_favicon' => '',
            'region' => 'CH',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',
            'timezone' => 'Europe/Zurich',
            'active_languages' => '["fr","en"]',
        ];
        foreach ($defaults as $key => $value) {
            \App\Models\CompanySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // Create sample admin RH + onboardee for all non-illizeo tenants
        if ($tenantId !== 'illizeo') {
            $rh = \App\Models\User::firstOrCreate(
                ['email' => "rh@{$tenantId}.com"],
                ['name' => 'Sophie Martin', 'password' => \Illuminate\Support\Facades\Hash::make('password')]
            );
            if ($rh->roles->isEmpty()) $rh->assignRole('admin_rh');

            $onboardee = \App\Models\User::firstOrCreate(
                ['email' => "collaborateur@{$tenantId}.com"],
                ['name' => 'Lucas Moreau', 'password' => \Illuminate\Support\Facades\Hash::make('password')]
            );
            if ($onboardee->roles->isEmpty()) $onboardee->assignRole('onboardee');
        }

        // Create demo users only for the illizeo tenant
        if ($tenantId === 'illizeo') {
            $superAdmin = \App\Models\User::factory()->create([
                'name' => 'Super Admin',
                'email' => 'super@illizeo.com',
            ]);
            $superAdmin->assignRole('super_admin');

            $adminRH = \App\Models\User::factory()->create([
                'name' => 'Wilfrid Dusseaux',
                'email' => 'wilfrid@illizeo.com',
            ]);
            $adminRH->assignRole('admin_rh');

            $manager = \App\Models\User::factory()->create([
                'name' => 'Mehdi Kessler',
                'email' => 'manager@illizeo.com',
            ]);
            $manager->assignRole('manager');

            $onboardee = \App\Models\User::factory()->create([
                'name' => 'Nadia Ferreira',
                'email' => 'nadia.ferreira@illizeo.com',
            ]);
            $onboardee->assignRole('onboardee');

            $collab = \App\Models\Collaborateur::where('email', 'nadia.ferreira@illizeo.com')->first();
            if ($collab) {
                $collab->update(['user_id' => $onboardee->id]);
            }

        // Onboarding teams
            $teamGE = \App\Models\OnboardingTeam::create(['nom' => 'Team Genève', 'description' => "Équipe d'accompagnement pour le site de Genève", 'site' => 'Genève']);
        \App\Models\OnboardingTeamMember::create(['team_id' => $teamGE->id, 'user_id' => $adminRH->id, 'role' => 'hrbp']);
        \App\Models\OnboardingTeamMember::create(['team_id' => $teamGE->id, 'user_id' => $manager->id, 'role' => 'manager']);

        $teamParis = \App\Models\OnboardingTeam::create(['nom' => 'Team Paris', 'description' => "Équipe d'accompagnement pour le site de Paris", 'site' => 'Paris']);
        \App\Models\OnboardingTeamMember::create(['team_id' => $teamParis->id, 'user_id' => $adminRH->id, 'role' => 'hrbp']);

        // Assign team to Nadia
        if ($collab) {
            \App\Models\CollaborateurAccompagnant::create(['collaborateur_id' => $collab->id, 'user_id' => $adminRH->id, 'role' => 'hrbp', 'team_id' => $teamGE->id]);
            \App\Models\CollaborateurAccompagnant::create(['collaborateur_id' => $collab->id, 'user_id' => $manager->id, 'role' => 'manager', 'team_id' => $teamGE->id]);
        }

        // Assign actions to Nadia (first 10 actions of Onboarding Standard)
        if ($collab) {
            $onboardingActions = \App\Models\Action::whereHas('parcours', fn ($q) => $q->where('nom', 'Onboarding Standard'))->limit(10)->get();
            foreach ($onboardingActions as $i => $action) {
                \App\Models\CollaborateurAction::create([
                    'collaborateur_id' => $collab->id,
                    'action_id' => $action->id,
                    'status' => $i < 3 ? 'termine' : ($i < 5 ? 'en_cours' : 'a_faire'),
                    'started_at' => $i < 5 ? now()->subDays(10 - $i) : null,
                    'completed_at' => $i < 3 ? now()->subDays(7 - $i) : null,
                ]);
            }
        }

        // Demo notifications
        \App\Services\NotificationService::welcome($onboardee->id, 'Nadia', 'Onboarding Standard');
        \App\Services\NotificationService::actionAssigned($onboardee->id, 'Compléter mon dossier administratif', 'J-30');
        \App\Services\NotificationService::actionAssigned($onboardee->id, 'Lire le règlement intérieur', 'J-7');
        \App\Services\NotificationService::actionAssigned($onboardee->id, 'Signer la charte informatique', 'J+0');
        \App\Services\NotificationService::reminder($onboardee->id, 'Compléter mon dossier administratif', 'J-30');
        \App\Services\NotificationService::docValidated($onboardee->id, "Pièce d'identité / Passeport");
        \App\Services\NotificationService::newCollaborateur($adminRH->id, 'Nadia Ferreira', 'Onboarding Standard');
        \App\Services\NotificationService::newMessage($adminRH->id, 'Nadia Ferreira');

        // Demo messages — IllizeoBot welcome + conversation
        $conv = \App\Models\Conversation::findOrCreateBetween($adminRH->id, $onboardee->id);

        \App\Models\Message::create([
            'conversation_id' => $conv->id, 'sender_id' => null,
            'content' => "👋 Bienvenue Nadia !\n\nJe suis IllizeoBot, votre assistant d'intégration. Votre parcours « Onboarding Standard » vient de commencer.\n\nJe vous guiderai à chaque étape. N'hésitez pas à poser vos questions ici ! 🚀",
            'is_bot' => true, 'bot_type' => 'welcome',
            'created_at' => now()->subDays(5),
        ]);

        \App\Models\Message::create([
            'conversation_id' => $conv->id, 'sender_id' => $adminRH->id,
            'content' => "Bonjour Nadia ! Bienvenue chez Illizeo. N'hésitez pas si vous avez des questions sur vos documents à fournir.",
            'created_at' => now()->subDays(5)->addHours(1),
        ]);

        \App\Models\Message::create([
            'conversation_id' => $conv->id, 'sender_id' => $onboardee->id,
            'content' => "Merci ! J'ai une question sur le formulaire de permis résident Vaud, est-ce que je dois le remplir en ligne ou le télécharger ?",
            'created_at' => now()->subDays(4),
        ]);

        \App\Models\Message::create([
            'conversation_id' => $conv->id, 'sender_id' => $adminRH->id,
            'content' => "Vous pouvez le télécharger depuis votre espace Documents, le remplir et le re-uploader. Si vous avez besoin d'aide, je suis là !",
            'created_at' => now()->subDays(4)->addHours(2),
        ]);

        \App\Models\Message::create([
            'conversation_id' => $conv->id, 'sender_id' => null,
            'content' => "⏰ Rappel : l'action « Compléter mon dossier administratif » arrive à échéance (J-30). Pensez à la compléter depuis votre tableau de bord.",
            'is_bot' => true, 'bot_type' => 'reminder',
            'created_at' => now()->subDays(2),
        ]);

        $conv->update(['last_message_at' => now()->subDays(2)]);

        // Conversation manager <-> onboardee
        $conv2 = \App\Models\Conversation::findOrCreateBetween($manager->id, $onboardee->id);
        \App\Models\Message::create([
            'conversation_id' => $conv2->id, 'sender_id' => $manager->id,
            'content' => "Salut Nadia, hâte de te rencontrer lundi ! On se retrouve à 9h à l'accueil.",
            'created_at' => now()->subDays(1),
        ]);
        $conv2->update(['last_message_at' => now()->subDays(1)]);
        } // end if illizeo demo tenant
    }
}
