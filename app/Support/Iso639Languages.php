<?php

namespace App\Support;

/**
 * Curated ISO 639-2 (bibliographic, 3-letter) language codes, keyed the same
 * way Plex/Emby/Jellyfin and FFmpeg tag audio/subtitle stream metadata
 * ('Language', 'languageCode', -metadata:s:a:N language=). Used to populate
 * the "Preferred Audio/Subtitle Track" selects so operators pick a code the
 * resolver/FFmpeg can actually match, instead of typing free text.
 */
final class Iso639Languages
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $languages = [
            'eng' => 'English',
            'jpn' => 'Japanese',
            'spa' => 'Spanish',
            'fre' => 'French',
            'ger' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'rus' => 'Russian',
            'chi' => 'Chinese',
            'kor' => 'Korean',
            'ara' => 'Arabic',
            'hin' => 'Hindi',
            'ben' => 'Bengali',
            'dut' => 'Dutch',
            'swe' => 'Swedish',
            'nor' => 'Norwegian',
            'dan' => 'Danish',
            'fin' => 'Finnish',
            'pol' => 'Polish',
            'tur' => 'Turkish',
            'gre' => 'Greek',
            'heb' => 'Hebrew',
            'tha' => 'Thai',
            'vie' => 'Vietnamese',
            'ind' => 'Indonesian',
            'may' => 'Malay',
            'fil' => 'Filipino',
            'ukr' => 'Ukrainian',
            'cze' => 'Czech',
            'slo' => 'Slovak',
            'hun' => 'Hungarian',
            'rum' => 'Romanian',
            'bul' => 'Bulgarian',
            'srp' => 'Serbian',
            'hrv' => 'Croatian',
            'slv' => 'Slovenian',
            'est' => 'Estonian',
            'lav' => 'Latvian',
            'lit' => 'Lithuanian',
            'per' => 'Persian',
            'urd' => 'Urdu',
            'tam' => 'Tamil',
            'tel' => 'Telugu',
            'mar' => 'Marathi',
            'guj' => 'Gujarati',
            'kan' => 'Kannada',
            'mal' => 'Malayalam',
            'pan' => 'Punjabi',
            'nep' => 'Nepali',
            'sin' => 'Sinhala',
            'bur' => 'Burmese',
            'khm' => 'Khmer',
            'lao' => 'Lao',
            'mon' => 'Mongolian',
            'geo' => 'Georgian',
            'arm' => 'Armenian',
            'aze' => 'Azerbaijani',
            'kaz' => 'Kazakh',
            'uzb' => 'Uzbek',
            'afr' => 'Afrikaans',
            'amh' => 'Amharic',
            'swa' => 'Swahili',
            'zul' => 'Zulu',
            'xho' => 'Xhosa',
            'som' => 'Somali',
            'hau' => 'Hausa',
            'yor' => 'Yoruba',
            'ibo' => 'Igbo',
            'ice' => 'Icelandic',
            'gle' => 'Irish',
            'wel' => 'Welsh',
            'cat' => 'Catalan',
            'baq' => 'Basque',
            'glg' => 'Galician',
            'alb' => 'Albanian',
            'mac' => 'Macedonian',
            'bos' => 'Bosnian',
            'mlt' => 'Maltese',
            'und' => 'Undetermined',
        ];

        asort($languages);

        return array_combine(
            array_keys($languages),
            array_map(
                fn (string $name, string $code): string => "{$name} ({$code})",
                $languages,
                array_keys($languages)
            )
        );
    }
}
