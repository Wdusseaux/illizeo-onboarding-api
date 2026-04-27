<?php

namespace Database\Seeders;

use App\Models\Quote;
use Illuminate\Database\Seeder;

class QuotesSeeder extends Seeder
{
    public function run(): void
    {
        $quotes = [
            // Onboarding & welcome
            ['text' => "Bienvenue dans l'aventure.", 'author' => "Toute l'équipe"],
            ['text' => "L'union fait la force.", 'author' => "Notre devise"],
            ['text' => "Innover ensemble, chaque jour.", 'author' => "Notre mission"],
            ['text' => "L'excellence est dans le détail.", 'author' => "Notre exigence"],
            ['text' => "Faire grandir les hommes pour faire grandir l'industrie.", 'author' => "Notre raison d'être"],

            // Inspiring leaders
            ['text' => "La meilleure façon de prédire l'avenir, c'est de le créer.", 'author' => "Peter Drucker"],
            ['text' => "Le succès, c'est tomber sept fois et se relever huit.", 'author' => "Proverbe japonais"],
            ['text' => "La simplicité est la sophistication suprême.", 'author' => "Leonardo da Vinci"],
            ['text' => "Faites de chaque jour votre chef-d'œuvre.", 'author' => "John Wooden"],
            ['text' => "Tout ce que vous pouvez imaginer est réel.", 'author' => "Pablo Picasso"],

            // Teamwork
            ['text' => "Seul on va plus vite, ensemble on va plus loin.", 'author' => "Proverbe africain"],
            ['text' => "La diversité est la pierre angulaire de l'innovation.", 'author' => "Anonyme"],
            ['text' => "Une équipe alignée est plus puissante qu'une somme d'individus.", 'author' => "Patrick Lencioni"],
            ['text' => "Le talent gagne des matchs, l'équipe gagne des championnats.", 'author' => "Michael Jordan"],
            ['text' => "Aucun de nous n'est aussi intelligent que nous tous.", 'author' => "Ken Blanchard"],

            // Growth & learning
            ['text' => "L'apprentissage est un trésor qui suivra son propriétaire partout.", 'author' => "Proverbe chinois"],
            ['text' => "Vivez comme si vous deviez mourir demain. Apprenez comme si vous deviez vivre toujours.", 'author' => "Gandhi"],
            ['text' => "L'échec n'est pas l'opposé du succès, c'en est une étape.", 'author' => "Arianna Huffington"],
            ['text' => "Le seul moyen de faire du bon travail est d'aimer ce que vous faites.", 'author' => "Steve Jobs"],
            ['text' => "Sortez de votre zone de confort. C'est là que la magie opère.", 'author' => "Anonyme"],

            // Resilience
            ['text' => "La persévérance est la mère de toutes les vertus.", 'author' => "Voltaire"],
            ['text' => "Ce qui ne te tue pas te rend plus fort.", 'author' => "Friedrich Nietzsche"],
            ['text' => "Les obstacles sont ces choses effrayantes que vous voyez quand vous quittez vos objectifs des yeux.", 'author' => "Henry Ford"],
            ['text' => "Tomber n'est pas un échec. Le vrai échec, c'est de rester là où vous êtes tombé.", 'author' => "Socrate"],
            ['text' => "Le courage n'est pas l'absence de peur, mais la capacité de la dépasser.", 'author' => "Mark Twain"],

            // Innovation
            ['text' => "L'innovation distingue le leader du suiveur.", 'author' => "Steve Jobs"],
            ['text' => "Si vous ne ratez pas, c'est que vous n'innovez pas assez.", 'author' => "Elon Musk"],
            ['text' => "L'imagination est plus importante que le savoir.", 'author' => "Albert Einstein"],
            ['text' => "Chaque problème est une opportunité déguisée.", 'author' => "John Adams"],
            ['text' => "Le futur appartient à ceux qui croient à la beauté de leurs rêves.", 'author' => "Eleanor Roosevelt"],

            // Customer & quality
            ['text' => "La qualité n'est jamais un accident. C'est toujours le résultat d'un effort intelligent.", 'author' => "John Ruskin"],
            ['text' => "Vos clients les plus insatisfaits sont votre meilleure source d'apprentissage.", 'author' => "Bill Gates"],
            ['text' => "Faites les choses simples extraordinairement bien.", 'author' => "Jim Rohn"],
            ['text' => "Bien fait vaut mieux que bien dit.", 'author' => "Benjamin Franklin"],
            ['text' => "L'excellence est un art que l'on n'atteint que par l'exercice constant.", 'author' => "Aristote"],

            // Leadership
            ['text' => "Le leadership, c'est l'art de faire faire à quelqu'un ce que vous voulez parce qu'il a envie de le faire.", 'author' => "Dwight Eisenhower"],
            ['text' => "Un grand leader ne crée pas de suiveurs, il crée d'autres leaders.", 'author' => "Tom Peters"],
            ['text' => "L'exemple n'est pas la principale façon d'influencer les autres. C'est la seule.", 'author' => "Albert Schweitzer"],
            ['text' => "Diriger, c'est servir.", 'author' => "Anonyme"],
            ['text' => "Avant d'être un grand leader, vous devez d'abord apprendre à être un grand suiveur.", 'author' => "Aristote"],

            // Wellbeing
            ['text' => "Prenez soin de vous, vous êtes irremplaçable.", 'author' => "Anonyme"],
            ['text' => "Le succès, c'est aimer sa vie et son travail.", 'author' => "Maya Angelou"],
            ['text' => "L'équilibre n'est pas quelque chose que vous trouvez, c'est quelque chose que vous créez.", 'author' => "Jana Kingsford"],
            ['text' => "Souriez, c'est la langue universelle.", 'author' => "Anonyme"],
            ['text' => "Le bonheur n'est pas une destination, c'est une manière de voyager.", 'author' => "Margaret Lee Runbeck"],

            // Misc
            ['text' => "Aujourd'hui est un cadeau, c'est pour cela qu'on l'appelle le présent.", 'author' => "Eleanor Roosevelt"],
            ['text' => "Faites ce que vous pouvez, avec ce que vous avez, là où vous êtes.", 'author' => "Theodore Roosevelt"],
            ['text' => "La curiosité est le moteur de toutes les découvertes.", 'author' => "Anonyme"],
            ['text' => "Une journée sans rire est une journée perdue.", 'author' => "Charlie Chaplin"],
            ['text' => "La gratitude transforme ce que nous avons en suffisance.", 'author' => "Melody Beattie"],
        ];

        foreach ($quotes as $q) {
            Quote::firstOrCreate(
                ['text' => $q['text']],
                [
                    'author' => $q['author'],
                    'source' => 'system',
                    'actif' => true,
                ]
            );
        }
    }
}
