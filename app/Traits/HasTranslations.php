<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasTranslations
{
    /**
     * Get all translations for the model.
     */
    abstract public function translations(): HasMany;

    /**
     * Get translation for a specific locale, falling back to application fallback locale.
     */
    public function translation(?string $locale = null): ?Model
    {
        $requestedLocale = $locale ?: app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');

        // Check already loaded relationship first to prevent extra queries
        if ($this->relationLoaded('translations')) {
            $match = $this->translations->firstWhere('locale', $requestedLocale);
            if ($match) {
                return $match;
            }

            $fallback = $this->translations->firstWhere('locale', $fallbackLocale);
            if ($fallback) {
                return $fallback;
            }

            return $this->translations->first();
        }

        // Query database if not loaded
        $translation = $this->translations()->where('locale', $requestedLocale)->first();
        if ($translation) {
            return $translation;
        }

        $fallback = $this->translations()->where('locale', $fallbackLocale)->first();
        if ($fallback) {
            return $fallback;
        }

        return $this->translations()->first();
    }

    /**
     * Convenient alias to get translation for a specific locale.
     */
    public function translate(?string $locale = null): ?Model
    {
        return $this->translation($locale);
    }

    /**
     * Get a translated attribute value.
     */
    public function getTranslatedAttribute(string $attribute, ?string $locale = null): ?string
    {
        $translation = $this->translation($locale);

        return $translation ? $translation->{$attribute} : null;
    }

    /**
     * Set or update a translation for a specific attribute and locale.
     */
    public function setTranslation(string $attribute, string $locale, ?string $value): Model
    {
        /** @var Model $translation */
        $translation = $this->translations()->firstOrNew(['locale' => $locale]);
        $translation->{$attribute} = $value;
        $translation->save();

        return $translation;
    }
}
