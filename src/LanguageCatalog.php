<?php

declare(strict_types=1);

namespace XArticlePdf;

final class LanguageCatalog
{
    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return [
            'Polish' => 'Polski',
            'English' => 'English',
            'German' => 'Deutsch',
            'French' => 'Français',
            'Spanish' => 'Español',
            'Italian' => 'Italiano',
            'Ukrainian' => 'Українська',
            'Russian' => 'Русский',
            'Czech' => 'Čeština',
            'Slovak' => 'Slovenčina',
            'Portuguese' => 'Português',
            'Dutch' => 'Nederlands',
            'Swedish' => 'Svenska',
            'Norwegian' => 'Norsk',
            'Danish' => 'Dansk',
            'Finnish' => 'Suomi',
            'Hungarian' => 'Magyar',
            'Romanian' => 'Română',
            'Bulgarian' => 'Български',
            'Croatian' => 'Hrvatski',
            'Serbian' => 'Srpski',
            'Greek' => 'Ελληνικά',
            'Turkish' => 'Türkçe',
            'Arabic' => 'العربية',
            'Hebrew' => 'עברית',
            'Chinese (Simplified)' => '中文 (简体)',
            'Chinese (Traditional)' => '中文 (繁體)',
            'Japanese' => '日本語',
            'Korean' => '한국어',
            'Hindi' => 'हिन्दी',
            'Vietnamese' => 'Tiếng Việt',
            'Thai' => 'ไทย',
            'Indonesian' => 'Bahasa Indonesia',
            'Malay' => 'Bahasa Melayu',
            'Lithuanian' => 'Lietuvių',
            'Latvian' => 'Latviešu',
            'Estonian' => 'Eesti',
            'Catalan' => 'Català',
            'Esperanto' => 'Esperanto',
        ];
    }

    public static function resolve(?string $posted, ?string $custom = null): ?string
    {
        $posted = trim((string) $posted);
        if ($posted === '' || $posted === 'off') {
            return null;
        }
        if ($posted === '__custom__') {
            $custom = trim((string) $custom);
            return self::isValidName($custom) ? $custom : null;
        }
        $options = self::options();
        if (isset($options[$posted])) {
            return $posted;
        }

        return self::isValidName($posted) ? $posted : null;
    }

    public static function isValidName(string $name): bool
    {
        if ($name === '' || mb_strlen($name) > 60) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}0-9][\p{L}0-9 .()\-\/]*$/u', $name);
    }
}
