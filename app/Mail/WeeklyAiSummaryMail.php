<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyAiSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $tenantName,
        public string $adminName,
        public string $weekLabel,
        public array $kpis,        // {new_collabs, completed_actions, late_actions, avg_progression, nps_score, mood_avg}
        public string $aiNarrative, // Claude-generated executive summary
        public array $recommendations, // Claude-generated action items
        public string $appUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "📊 Récap hebdo Illizeo — {$this->weekLabel}");
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $name = htmlspecialchars($this->adminName);
        $tenant = htmlspecialchars($this->tenantName);
        $week = htmlspecialchars($this->weekLabel);
        $url = htmlspecialchars($this->appUrl);
        $narrative = nl2br(htmlspecialchars($this->aiNarrative));

        $k = $this->kpis;
        $newCollabs = (int) ($k['new_collabs'] ?? 0);
        $completedActions = (int) ($k['completed_actions'] ?? 0);
        $lateActions = (int) ($k['late_actions'] ?? 0);
        $avgProg = round((float) ($k['avg_progression'] ?? 0));
        $npsScore = $k['nps_score'] !== null ? (int) $k['nps_score'] : null;
        $moodAvg = $k['mood_avg'] !== null ? round((float) $k['mood_avg'], 1) : null;

        $recoHtml = '';
        foreach ($this->recommendations as $reco) {
            $recoHtml .= '<li style="margin-bottom: 8px; line-height: 1.5;">' . htmlspecialchars($reco) . '</li>';
        }
        if (empty($recoHtml)) {
            $recoHtml = '<li style="color: #888;">Aucune recommandation cette semaine — bonne nouvelle !</li>';
        }

        $kpiCards = $this->buildKpiCards($newCollabs, $completedActions, $lateActions, $avgProg, $npsScore, $moodAvg);

        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 640px; margin: 0 auto; padding: 20px; color: #333;">
    <div style="text-align: center; margin-bottom: 24px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
    </div>

    <div style="background: linear-gradient(135deg, #1a1a2e 0%, #2d2d4d 100%); color: #fff; border-radius: 16px; padding: 28px 28px; margin-bottom: 24px;">
        <div style="font-size: 11px; letter-spacing: 2px; text-transform: uppercase; opacity: 0.7; margin-bottom: 4px;">📊 Récap hebdomadaire</div>
        <div style="font-size: 22px; font-weight: 700; margin-bottom: 6px;">{$tenant}</div>
        <div style="font-size: 13px; opacity: 0.85;">Semaine du {$week}</div>
    </div>

    <p style="font-size: 14px;">Bonjour <strong>{$name}</strong>,</p>
    <p style="font-size: 13px; line-height: 1.6; color: #555;">Voici votre synthèse de la semaine, générée par Claude à partir de vos données Illizeo.</p>

    <h3 style="font-size: 14px; font-weight: 700; color: #1a1a2e; margin-top: 24px; margin-bottom: 12px;">📈 Indicateurs clés</h3>
    <div>{$kpiCards}</div>

    <h3 style="font-size: 14px; font-weight: 700; color: #1a1a2e; margin-top: 24px; margin-bottom: 12px;">📝 Synthèse IA</h3>
    <div style="background: #f8f9fa; border-left: 4px solid #E91E63; padding: 16px 20px; border-radius: 4px; font-size: 13px; line-height: 1.7; color: #444;">
        {$narrative}
    </div>

    <h3 style="font-size: 14px; font-weight: 700; color: #1a1a2e; margin-top: 24px; margin-bottom: 12px;">💡 Actions recommandées</h3>
    <ul style="font-size: 13px; color: #444; padding-left: 20px; margin: 0;">{$recoHtml}</ul>

    <div style="text-align: center; margin: 32px 0 16px;">
        <a href="{$url}" style="display: inline-block; padding: 12px 28px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">Ouvrir mon tableau de bord →</a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #888; text-align: center; line-height: 1.6;">
        Vous recevez cet email parce que vous êtes admin de l'espace {$tenant}.<br>
        <br>
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse
    </div>
</div>
HTML;
    }

    private function buildKpiCards(int $newCollabs, int $completed, int $late, int $avgProg, ?int $nps, ?float $mood): string
    {
        $items = [
            ['label' => 'Nouveaux arrivants', 'value' => (string) $newCollabs, 'color' => '#1A73E8'],
            ['label' => 'Actions validées', 'value' => (string) $completed, 'color' => '#2E7D32'],
            ['label' => 'Actions en retard', 'value' => (string) $late, 'color' => $late > 5 ? '#C62828' : '#F9A825'],
            ['label' => 'Progression moyenne', 'value' => $avgProg . '%', 'color' => '#9C27B0'],
        ];
        if ($nps !== null) {
            $items[] = ['label' => 'Score NPS', 'value' => (string) $nps, 'color' => $nps >= 50 ? '#2E7D32' : ($nps >= 0 ? '#F9A825' : '#C62828')];
        }
        if ($mood !== null) {
            $items[] = ['label' => 'Humeur moy. (/5)', 'value' => (string) $mood, 'color' => $mood >= 4 ? '#2E7D32' : ($mood >= 3 ? '#F9A825' : '#C62828')];
        }

        $rows = '';
        $row = '';
        $count = 0;
        foreach ($items as $i => $item) {
            $row .= '<td style="width: 50%; padding: 6px;"><div style="background: #f8f9fa; border-radius: 10px; padding: 14px 16px; border-left: 4px solid ' . $item['color'] . ';"><div style="font-size: 22px; font-weight: 700; color: ' . $item['color'] . ';">' . $item['value'] . '</div><div style="font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px;">' . $item['label'] . '</div></div></td>';
            $count++;
            if ($count % 2 === 0) {
                $rows .= '<tr>' . $row . '</tr>';
                $row = '';
            }
        }
        if (!empty($row)) {
            $row .= '<td style="width: 50%; padding: 6px;"></td>';
            $rows .= '<tr>' . $row . '</tr>';
        }

        return '<table style="width: 100%; border-collapse: collapse;">' . $rows . '</table>';
    }
}
