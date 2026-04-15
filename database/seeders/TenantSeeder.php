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
            if ($onboardee->roles->isEmpty()) $onboardee->assignRole('collaborateur');
        }

        // Create demo users only for the illizeo tenant
        if ($tenantId === 'illizeo') {
            $pw = \Illuminate\Support\Facades\Hash::make('password');

            $superAdmin = \App\Models\User::create(['name' => 'Super Admin', 'email' => 'super@illizeo.com', 'password' => $pw]);
            $superAdmin->assignRole('super_admin');

            $adminRH = \App\Models\User::create(['name' => 'Wilfrid Dusseaux', 'email' => 'wilfrid@illizeo.com', 'password' => $pw]);
            $adminRH->assignRole('admin_rh');

            $manager = \App\Models\User::create(['name' => 'Mehdi Kessler', 'email' => 'manager@illizeo.com', 'password' => $pw]);
            $manager->assignRole('manager');

            $onboardee = \App\Models\User::create(['name' => 'Nadia Ferreira', 'email' => 'nadia.ferreira@illizeo.com', 'password' => $pw]);
            $onboardee->assignRole('collaborateur');

            // Extra onboardee users linked to collaborateurs
            foreach ([
                ['name' => 'Inès Carpentier', 'email' => 'ines.carpentier@illizeo.com'],
                ['name' => 'Antoine Morel', 'email' => 'antoine.morel@illizeo.com'],
                ['name' => 'Youssef Hadj', 'email' => 'youssef.hadj@illizeo.com'],
                ['name' => 'Clara Vogel', 'email' => 'clara.vogel@illizeo.com'],
            ] as $userData) {
                $u = \App\Models\User::create(['name' => $userData['name'], 'email' => $userData['email'], 'password' => $pw]);
                $u->assignRole('collaborateur');
                $c = \App\Models\Collaborateur::where('email', $userData['email'])->first();
                if ($c) $c->update(['user_id' => $u->id]);
            }

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

        // Assign accompagnants to ALL collaborateurs
        foreach (\App\Models\Collaborateur::all() as $c) {
            \App\Models\CollaborateurAccompagnant::firstOrCreate(
                ['collaborateur_id' => $c->id, 'user_id' => $adminRH->id],
                ['role' => 'hrbp', 'team_id' => $teamGE->id]
            );
            \App\Models\CollaborateurAccompagnant::firstOrCreate(
                ['collaborateur_id' => $c->id, 'user_id' => $manager->id],
                ['role' => 'manager', 'team_id' => $teamGE->id]
            );
        }

        // Assign actions to all collaborateurs based on their parcours
        foreach (\App\Models\Collaborateur::all() as $c) {
            if (!$c->parcours_id) continue;
            $parcours = \App\Models\Parcours::find($c->parcours_id);
            if (!$parcours) continue;
            $actions = \App\Models\Action::where('parcours_id', $parcours->id)->get();
            foreach ($actions->values() as $i => $action) {
                $done = $i < $c->actions_completes;
                $inProgress = !$done && $i < ($c->actions_completes + 2);
                \App\Models\CollaborateurAction::create([
                    'collaborateur_id' => $c->id,
                    'action_id' => $action->id,
                    'status' => $done ? 'termine' : ($inProgress ? 'en_cours' : 'a_faire'),
                    'started_at' => ($done || $inProgress) ? now()->subDays(10 - $i) : null,
                    'completed_at' => $done ? now()->subDays(7 - $i) : null,
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
