<?php

namespace App\Services;

use RuntimeException;

class AedAiRegexSuggestionService
{
    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return [
            'English' => __('English'),
            'German' => __('German'),
            'French' => __('French'),
            'Spanish' => __('Spanish'),
            'Italian' => __('Italian'),
            'Portuguese' => __('Portuguese'),
            'Dutch' => __('Dutch'),
            'Polish' => __('Polish'),
            'Turkish' => __('Turkish'),
            'Custom' => __('Custom'),
        ];
    }

    public function defaultLanguage(): string
    {
        return match (strtolower(str_replace('_', '-', app()->getLocale()))) {
            'de', 'de-de', 'de-at', 'de-ch' => 'German',
            'fr', 'fr-fr', 'fr-ca' => 'French',
            'es', 'es-es', 'es-mx' => 'Spanish',
            'it', 'it-it' => 'Italian',
            'pt', 'pt-br', 'pt-pt' => 'Portuguese',
            'nl', 'nl-nl' => 'Dutch',
            'pl', 'pl-pl' => 'Polish',
            'tr', 'tr-tr' => 'Turkish',
            default => 'English',
        };
    }

    public function buildSystemPrompt(string $targetLanguage): string
    {
        $targetLanguage = $this->normalizeTargetLanguage($targetLanguage);

        return <<<PROMPT
You are an expert PHP regex engineer for IPTV AED profiles.

Analyse IPTV channel titles and return one JSON object that fills the complete AED profile. Return only JSON. No markdown. No code fences. No explanation.

Rules:
- Return exactly these keys: title_regex, team_delimiter, time_regex, time_format, date_regex, date_format, source_timezone, output_timezone, event_duration_minutes, title_format, description_format, pre_event_format, post_event_format, no_event_format, category.
- Use PHP regex fragments without delimiters. Do not wrap regex with slashes.
- Regex patterns must work with preg_match('/' . pattern . '/u', title).
- Prefer capture group 1 for extracted title, time, and date.
- For title_regex, extract the meaningful event name. Do not include numeric prefixes, channel branding, quality tags, language tags, or trailing date/time blocks.
- If home and away teams are clear, set team_delimiter to a literal delimiter that splits the final title. Otherwise use an empty string.
- For time_regex, capture only the time string. For date_regex, capture only the date string.
- For time_format and date_format, use PHP date formats that match the captured strings. Separate alternatives with | when needed.
- Infer source_timezone from explicit timezone abbreviations. ET, EST, EDT means America/New_York. CT, CST, CDT means America/Chicago. MT, MST, MDT means America/Denver. PT, PST, PDT means America/Los_Angeles. GMT or UTC means UTC. BST means Europe/London. CET or CEST means Europe/Paris.
- Set output_timezone to the user's desired display timezone when obvious from the samples. Otherwise use UTC.
- Set event_duration_minutes to a reasonable duration based on the samples. Use 180 when unknown.
- Output formats may use only these variables: {title}, {team1}, {team2}, {channel}, {date}, {time}, {time_until}.
- Keep variable names exactly unchanged and in English, including braces.
- Write all human-facing text in {$targetLanguage}. This includes title_format wording, description_format wording, pre_event_format, post_event_format, no_event_format, and category.
- category should be a concise EPG category in {$targetLanguage} when possible. Prefer a simple category such as Sports, Movie, Series, News, Kids, Documentary, Music, or Education translated to {$targetLanguage}.
- Never return null. Use empty strings only when the field should be left blank.

Example JSON shape:
{"title_regex":"^EVENT\\s*\\d+:\\s*(.+?)\\s*\\(","team_delimiter":" vs ","time_regex":"(\\d{1,2}:\\d{2}\\s*[AP]M)\\s+ET","time_format":"g:i A","date_regex":"\\((\\d{1,2}\\.\\d{1,2})","date_format":"n.j","source_timezone":"America/New_York","output_timezone":"Europe/Berlin","event_duration_minutes":180,"title_format":"{title}","description_format":"{title} am {date} um {time}","pre_event_format":"Live in {time_until}: {title}","post_event_format":"Sendeschluss","no_event_format":"{channel}","category":"Sport"}
PROMPT;
    }

    /**
     * @param  array<int, string>  $titles
     */
    public function buildUserPrompt(array $titles, string $targetLanguage): string
    {
        $sampleList = implode("\n", array_slice($this->preferStructuredTitles($titles), 0, 20));
        $targetLanguage = $this->normalizeTargetLanguage($targetLanguage);

        return "Target output language: {$targetLanguage}\n\nAnalyse these channel titles:\n\n{$sampleList}";
    }

    /**
     * @return array<string, mixed>
     */
    public function parseResponse(string $text): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', trim($text)) ?? trim($text);
        $clean = preg_replace('/```\s*$/m', '', $clean) ?? $clean;

        if (preg_match('/\{.*\}/s', $clean, $jsonMatch)) {
            $clean = $jsonMatch[0];
        }

        $suggestions = json_decode(trim($clean), true);

        if (! is_array($suggestions)) {
            throw new RuntimeException('AI returned invalid JSON: '.substr($text, 0, 300));
        }

        return array_replace($this->defaultSuggestions(), array_intersect_key($suggestions, $this->defaultSuggestions()));
    }

    /**
     * @param  array<int, string>  $titles
     * @return array<int, string>
     */
    private function preferStructuredTitles(array $titles): array
    {
        $cleanTitles = array_values(array_filter(array_map('trim', $titles)));
        $structured = array_values(array_filter($cleanTitles, fn (string $title): bool => (bool) preg_match('/:\s*\S/', $title)));

        return $structured ?: $cleanTitles;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSuggestions(): array
    {
        return [
            'title_regex' => '',
            'team_delimiter' => '',
            'time_regex' => '',
            'time_format' => '',
            'date_regex' => '',
            'date_format' => '',
            'source_timezone' => 'UTC',
            'output_timezone' => 'UTC',
            'event_duration_minutes' => 180,
            'title_format' => '{title}',
            'description_format' => '',
            'pre_event_format' => '',
            'post_event_format' => '',
            'no_event_format' => '{channel}',
            'category' => '',
        ];
    }

    private function normalizeTargetLanguage(string $targetLanguage): string
    {
        $targetLanguage = trim($targetLanguage);

        return $targetLanguage !== '' ? $targetLanguage : 'English';
    }
}
