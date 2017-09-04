<?php namespace Arcanedev\Localization\Traits;

use Arcanedev\Localization\Events\TranslationHasBeenSet;
use Arcanedev\Localization\Exceptions\UntranslatableAttributeException;
use Illuminate\Support\Str;

/**
 * Trait     HasTranslations
 *
 * @package  Arcanedev\Localization\Traits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait HasTranslations
{
    /* -----------------------------------------------------------------
     |  Main Methods
     | -----------------------------------------------------------------
     */

    /**
     * Get the translatable attributes.
     *
     * @return array
     */
    abstract public function getTranslatableAttributes();

    /**
     * Get the translated attribute value.
     *
     * @param  string  $key
     *
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        return $this->isTranslatableAttribute($key)
            ? $this->getTranslation($key, config('app.locale'))
            : parent::getAttributeValue($key);
    }

    /**
     * Get the translated attribute (alias).
     *
     * @param  string  $key
     * @param  string  $locale
     *
     * @return mixed
     */
    public function trans($key, $locale = '')
    {
        return $this->getTranslation($key, $locale);
    }

    /***
     * Get the translated attribute.
     *
     * @param  string  $key
     * @param  string  $locale
     * @param  bool    $useFallback
     *
     * @return mixed
     */
    public function getTranslation($key, $locale, $useFallback = true)
    {
        $locale       = $this->normalizeLocale($key, $locale, $useFallback);
        $translations = $this->getTranslations($key);
        $translation  = $translations[$locale] ?? '';

        return $this->hasGetMutator($key)
            ? $this->mutateAttribute($key, $translation)
            : $translation;
    }

    /**
     * Get the translations for the given key.
     *
     * @param  string  $key
     *
     * @return array
     */
    public function getTranslations($key)
    {
        $this->guardAgainstUntranslatableAttribute($key);

        return json_decode($this->getAttributeFromArray($key) ?: '{}', true);
    }

    /**
     * Set a translation.
     *
     * @param  string  $key
     * @param  string  $locale
     * @param  string  $value
     *
     * @return self
     */
    public function setTranslation($key, $locale, $value)
    {
        $this->guardAgainstUntranslatableAttribute($key);

        $translations = $this->getTranslations($key);
        $oldValue     = $translations[$locale] ?? '';

        if ($this->hasSetMutator($key))
            $value = $this->{'set'.Str::studly($key).'Attribute'}($value);

        $translations[$locale]  = $value;
        $this->attributes[$key] = json_encode($translations);

        event(new TranslationHasBeenSet($this, $key, $locale, $oldValue, $value));

        return $this;
    }

    /**
     * Set the translations.
     *
     * @param  string  $key
     * @param  array   $translations
     *
     * @return self
     */
    public function setTranslations($key, array $translations)
    {
        $this->guardAgainstUntranslatableAttribute($key);

        foreach ($translations as $locale => $translation) {
            $this->setTranslation($key, $locale, $translation);
        }

        return $this;
    }

    /**
     * Forget a translation.
     *
     * @param  string  $key
     * @param  string  $locale
     *
     * @return self
     */
    public function forgetTranslation($key, $locale)
    {
        $translations = $this->getTranslations($key);
        unset($translations[$locale]);

        if ($this->hasSetMutator($key))
            $this->attributes[$key] = json_encode($this->mutateTranslations($key, $translations));
        else
            $this->setAttribute($key, $translations);

        return $this;
    }

    /**
     * Forget all the translations by the given locale.
     *
     * @param  string  $locale
     *
     * @return self
     */
    public function flushTranslations($locale)
    {
        collect($this->getTranslatableAttributes())->each(function ($attribute) use ($locale) {
            $this->forgetTranslation($attribute, $locale);
        });

        return $this;
    }

    /**
     * Get the translated attribute's locales
     *
     * @param  string  $key
     *
     * @return array
     */
    public function getTranslatedLocales($key)
    {
        return array_keys($this->getTranslations($key));
    }

    /**
     * Check if the attribute is translatable.
     *
     * @param  string  $key
     *
     * @return bool
     */
    public function isTranslatableAttribute($key)
    {
        return in_array($key, $this->getTranslatableAttributes());
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Guard against untranslatable attribute.
     *
     * @param  string  $key
     *
     * @throws \Arcanedev\Localization\Exceptions\UntranslatableAttributeException
     */
    protected function guardAgainstUntranslatableAttribute($key)
    {
        if ( ! $this->isTranslatableAttribute($key)) {
            $translatable = implode(', ', $this->getTranslatableAttributes());

            throw new UntranslatableAttributeException(
                "The attribute `{$key}` is untranslatable because it's not available in the translatable array: `$translatable`"
            );
        }
    }

    /**
     * Normalize the locale.
     *
     * @param  string  $key
     * @param  string  $locale
     * @param  bool    $useFallback
     *
     * @return string
     */
    protected function normalizeLocale($key, $locale, $useFallback)
    {
        if (in_array($locale, $this->getTranslatedLocales($key)) || ! $useFallback)
            return $locale;

        return is_null($fallbackLocale = config('app.fallback_locale')) ? $locale : $fallbackLocale;
    }

    /**
     * Mutate many translations.
     *
     * @param  string  $key
     * @param  array   $translations
     *
     * @return string
     */
    protected function mutateTranslations($key, array $translations)
    {
        $method = 'set'.Str::studly($key).'Attribute';

        return array_map(function ($value) use ($method) {
            return $this->{$method}($value);
        }, $translations);
    }

    /* -----------------------------------------------------------------
     |  Eloquent Methods
     | -----------------------------------------------------------------
     */

    /**
     * Get the casts array.
     *
     * @return array
     */
    public function getCasts()
    {
        return array_merge(
            parent::getCasts(), array_fill_keys($this->getTranslatableAttributes(), 'array')
        );
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     * @param  string  $key
     *
     * @return bool
     */
    abstract public function hasGetMutator($key);

    /**
     * Get the value of an attribute using its mutator.
     *
     * @param  string  $key
     * @param  mixed   $value
     *
     * @return mixed
     */
    abstract protected function mutateAttribute($key, $value);

    /**
     * Get an attribute from the $attributes array.
     *
     * @param  string  $key
     *
     * @return mixed
     */
    abstract protected function getAttributeFromArray($key);

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed   $value
     *
     * @return self
     */
    abstract public function setAttribute($key, $value);

    /**
     * Determine if a set mutator exists for an attribute.
     *
     * @param  string  $key
     *
     * @return bool
     */
    abstract public function hasSetMutator($key);

    /**
     * Encode the given value as JSON.
     *
     * @param  mixed  $value
     *
     * @return string
     */
    abstract protected function asJson($value);
}
