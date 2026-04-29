<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Generate minimal demo .docx templates for each contract that has no fichier_path,
 * upload them to storage/app/contrat-templates/{id}_<filename>, and update fichier_path.
 *
 * Idempotent: only seeds contracts whose fichier_path is NULL or whose file is missing on disk.
 * The templates use ${var} placeholders compatible with PhpWord TemplateProcessor and
 * with the variable map produced by ContratController::buildVariableMap().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contrats')) {
            return;
        }
        if (!class_exists(\PhpOffice\PhpWord\PhpWord::class)) {
            return;
        }

        $contrats = DB::table('contrats')->get();
        foreach ($contrats as $contrat) {
            if ($contrat->fichier_path && Storage::disk('local')->exists($contrat->fichier_path)) {
                continue; // already has a template
            }

            $type = strtolower($contrat->type ?? '');
            $juridiction = $contrat->juridiction ?? 'Droit Suisse';
            $body = $this->bodyForType($type, $juridiction);

            $phpWord = new PhpWord();
            $section = $phpWord->addSection([
                'marginLeft' => 1500, 'marginRight' => 1500,
                'marginTop' => 1500, 'marginBottom' => 1500,
            ]);

            // Header
            $section->addText(
                strtoupper($contrat->nom ?? 'CONTRAT'),
                ['bold' => true, 'size' => 16, 'color' => 'E41076'],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 200]
            );
            $section->addText('Référence : '.($contrat->type ?? '').' — '.$juridiction, ['italic' => true, 'size' => 10, 'color' => '666666'], ['alignment' => Jc::CENTER, 'spaceAfter' => 400]);
            $section->addTextBreak(1);

            // Parties
            $section->addText('ENTRE LES SOUSSIGNÉS', ['bold' => true, 'size' => 11, 'color' => '333333'], ['spaceAfter' => 120]);
            $section->addText('La société ${company_name}, dont le siège social est situé ${company_address}, ci-après « l\'Employeur »,', null, ['spaceAfter' => 100]);
            $section->addText('D\'une part,', ['italic' => true], ['spaceAfter' => 200]);
            $section->addText('ET', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);
            $section->addText('${full_name}, né(e) le ${birthday}, de nationalité ${nationality}, demeurant ${address}, ${postal_code} ${city} (${country}), ci-après « le Salarié »,', null, ['spaceAfter' => 100]);
            $section->addText('D\'autre part,', ['italic' => true], ['spaceAfter' => 300]);

            // Body sections (vary per type)
            foreach ($body as $h => $p) {
                $section->addText($h, ['bold' => true, 'size' => 11, 'color' => 'E41076'], ['spaceBefore' => 240, 'spaceAfter' => 120]);
                $section->addText($p, null, ['spaceAfter' => 120]);
            }

            // Signatures
            $section->addPageBreak();
            $section->addText('Fait à ${site}, le ${document_date}', ['italic' => true], ['alignment' => Jc::END, 'spaceAfter' => 600]);
            $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
            $table->addRow();
            $cell1 = $table->addCell(4500);
            $cell1->addText('Pour l\'Employeur', ['bold' => true]);
            $cell1->addText('${supervisor_full_name}');
            $cell1->addText('${supervisor_position}', ['italic' => true, 'size' => 10]);
            $cell1->addTextBreak(3);
            $cell1->addText('_______________________');
            $cell1->addText('Signature', ['italic' => true, 'size' => 9, 'color' => '888888']);
            $cell2 = $table->addCell(4500);
            $cell2->addText('Le Salarié', ['bold' => true]);
            $cell2->addText('${full_name}');
            $cell2->addTextBreak(4);
            $cell2->addText('_______________________');
            $cell2->addText('Signature précédée de la mention « Lu et approuvé »', ['italic' => true, 'size' => 9, 'color' => '888888']);

            // Persist
            $safeFilename = $contrat->fichier && preg_match('/\.docx$/i', $contrat->fichier)
                ? $contrat->fichier
                : ($this->slug($contrat->nom ?? 'contrat').'.docx');
            $relPath = 'contrat-templates/'.$contrat->id.'_'.$safeFilename;
            $absPath = Storage::disk('local')->path($relPath);
            @mkdir(dirname($absPath), 0775, true);
            IOFactory::createWriter($phpWord, 'Word2007')->save($absPath);

            DB::table('contrats')
                ->where('id', $contrat->id)
                ->update([
                    'fichier' => $safeFilename,
                    'fichier_path' => $relPath,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive on purpose: we do not remove uploaded files or unset paths.
        // Reverting the migration would risk wiping user-uploaded templates.
    }

    private function bodyForType(string $type, string $juridiction): array
    {
        $isFr = str_contains(strtolower($juridiction), 'français') || str_contains(strtolower($juridiction), 'france');

        // Common base for permanent contracts
        $cdiBlocks = [
            'Article 1 — Engagement et fonctions' => 'Le Salarié est engagé en qualité de ${position}, au sein du département ${department_name}, à compter du ${hire_date}. Il est placé sous la responsabilité hiérarchique de ${supervisor_full_name} (${supervisor_position}).',
            'Article 2 — Durée du contrat' => 'Le présent contrat est conclu pour une durée indéterminée.'
                .($isFr ? ' Conformément aux dispositions du Code du travail français applicable.' : ' Conformément aux dispositions du Code des obligations suisse applicable.'),
            'Article 3 — Période d\'essai' => 'La période d\'essai prendra fin le ${probation_end_date}. Pendant cette période, chacune des parties pourra mettre fin au contrat dans les conditions prévues par la loi.',
            'Article 4 — Lieu de travail' => 'Le lieu de travail principal est fixé à ${site} (bureau ${office_name}). Le Salarié pourra être amené à se déplacer ponctuellement selon les besoins du service.',
            'Article 5 — Durée du travail' => 'La durée hebdomadaire de travail est fixée à ${weekly_working_hours} heures, sur la base d\'un taux d\'activité de ${fte}.',
            'Article 6 — Rémunération' => 'En contrepartie de son activité, le Salarié percevra une rémunération brute annuelle de ${fix_salary} ${currency}, versée sur 12 mensualités.',
            'Article 7 — Congés payés' => 'Le Salarié bénéficie des congés payés dans les conditions légales et conventionnelles applicables.',
        ];

        // CDD: add term
        $cddBlocks = $cdiBlocks;
        $cddBlocks['Article 2 — Durée du contrat'] = 'Le présent contrat est conclu pour une durée déterminée, prenant effet le ${hire_date} et prenant fin le ${contract_end_date}.';

        // Stage
        $stageBlocks = [
            'Article 1 — Objet' => 'La présente convention règle les rapports entre l\'établissement, l\'entreprise d\'accueil et le stagiaire ${full_name} dans le cadre d\'un stage de formation pratique.',
            'Article 2 — Durée du stage' => 'Le stage débute le ${hire_date} et se termine le ${contract_end_date}. La durée hebdomadaire est de ${weekly_working_hours} heures.',
            'Article 3 — Mission' => 'Le stagiaire est accueilli au sein du département ${department_name} pour exercer les missions de ${position}, sous la responsabilité de ${supervisor_full_name}.',
            'Article 4 — Gratification' => 'Le stagiaire percevra une gratification mensuelle de ${fix_salary} ${currency}.',
            'Article 5 — Lieu' => 'Le stage se déroule à ${site}.',
        ];

        // Alternance
        $alternanceBlocks = [
            'Article 1 — Engagement' => 'Le présent contrat d\'alternance est conclu entre l\'Employeur, le Salarié ${full_name} et le centre de formation, à compter du ${hire_date} et jusqu\'au ${contract_end_date}.',
            'Article 2 — Mission en entreprise' => 'L\'alternant exercera les fonctions de ${position} au sein du département ${department_name}, sous la responsabilité de ${supervisor_full_name}.',
            'Article 3 — Rythme' => 'Le rythme d\'alternance est défini par le calendrier du centre de formation. Durée moyenne en entreprise : ${weekly_working_hours} heures par semaine.',
            'Article 4 — Rémunération' => 'L\'alternant percevra une rémunération brute mensuelle de ${fix_salary} ${currency}, conforme à la grille légale applicable.',
        ];

        // Avenant (mobilité)
        $avenantBlocks = [
            'Article 1 — Objet de l\'avenant' => 'Le présent avenant a pour objet de modifier les conditions du contrat de travail initial de ${full_name} dans le cadre d\'une mobilité interne.',
            'Article 2 — Nouvelle fonction' => 'À compter du ${hire_date}, le Salarié occupe les fonctions de ${position} au sein du département ${department_name}, sous la responsabilité de ${supervisor_full_name}.',
            'Article 3 — Lieu de travail' => 'Le nouveau lieu de travail est fixé à ${site}.',
            'Article 4 — Rémunération' => 'La rémunération brute annuelle est portée à ${fix_salary} ${currency}.',
            'Article 5 — Autres dispositions' => 'Toutes les autres clauses du contrat initial demeurent inchangées.',
        ];

        return match ($type) {
            'cdd' => $cddBlocks,
            'stage' => $stageBlocks,
            'alternance' => $alternanceBlocks,
            'avenant' => $avenantBlocks,
            default => $cdiBlocks, // CDI + fallback
        };
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        return trim($s, '_') ?: 'contrat';
    }
};
