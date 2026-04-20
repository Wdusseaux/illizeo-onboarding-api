<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTranslation extends Model
{
    protected $fillable = ['source_lang', 'target_lang', 'source_text', 'translated_text', 'hash'];

    public static function cacheKey(string $sourceLang, string $targetLang, string $text): string
    {
        return hash('sha256', "{$sourceLang}|{$targetLang}|{$text}");
    }

    public static function findCached(string $sourceLang, string $targetLang, string $text): ?string
    {
        $hash = self::cacheKey($sourceLang, $targetLang, $text);
        return self::where('hash', $hash)->value('translated_text');
    }

    public static function saveCache(string $sourceLang, string $targetLang, string $text, string $translation): void
    {
        self::updateOrCreate(
            ['hash' => self::cacheKey($sourceLang, $targetLang, $text)],
            ['source_lang' => $sourceLang, 'target_lang' => $targetLang, 'source_text' => $text, 'translated_text' => $translation]
        );
    }
}
