<?php namespace Iginikolaev\Translatable;

use App;
use Iginikolaev\Translatable\Exception\LocalesNotDefinedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait Translatable
{
    public static function bootTranslatable()
    {
        static::addGlobalScope('joinTranslation', function (Builder $builder) {
            return static::scopeJoinTranslation($builder, null);
        });
    }

    /**
     * Alias for getTranslation()
     *
     * @param string|null $locale
     * @param bool $withFallback
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translate($locale = null, $withFallback = false)
    {
        return $this->getTranslation($locale, $withFallback);
    }

    /**
     * Alias for getTranslation()
     *
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translateOrDefault($locale)
    {
        return $this->getTranslation($locale, true);
    }

    /**
     * Alias for getTranslationOrNew()
     *
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function translateOrNew($locale)
    {
        return $this->getTranslationOrNew($locale);
    }

    /**
     * @param string|null $locale
     * @param bool $withFallback
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getTranslation($locale = null, $withFallback = null)
    {
        $configFallbackLocale = $this->getFallbackLocale($locale);
        $locale = $locale ? : $this->locale();
        $withFallback = $withFallback === null ? $this->useFallback() : $withFallback;
        $fallbackLocale = $this->getFallbackLocale($locale);

        if ($this->isJoinTranslated()) {
            return null;
        }

        if ($translation = $this->getTranslationByLocaleKey($locale)) {
            return $translation;
        }
        if ($withFallback && $fallbackLocale) {
            if ($translation = $this->getTranslationByLocaleKey($fallbackLocale)) {
                return $translation;
            }
            if ($translation = $this->getTranslationByLocaleKey($configFallbackLocale)) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * @param string|null $locale
     *
     * @return bool
     */
    public function hasTranslation($locale = null)
    {
        $locale = $locale ? : $this->locale();

        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $locale) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function getTranslationModelName()
    {
        return $this->translationModel ? : $this->getTranslationModelNameDefault();
    }

    /**
     * @return string
     */
    public function getTranslationModelNameDefault()
    {
        $config = App::make('config');

        return get_class($this) . $config->get('translatable.translation_suffix', 'Translation');
    }

    /**
     * @return string
     */
    public function getRelationKey()
    {
        if ($this->translationForeignKey) {
            $key = $this->translationForeignKey;
        } elseif ($this->primaryKey !== 'id') {
            $key = $this->primaryKey;
        } else {
            $key = $this->getForeignKey();
        }

        return $key;
    }

    /**
     * @return string
     */
    public function getLocaleKey()
    {
        $config = App::make('config');

        return $this->localeKey ? : $config->get('translatable.locale_key', 'locale');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function translations()
    {
        return $this->hasMany($this->getTranslationModelName(), $this->getRelationKey());
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (str_contains($key, ':')) {
            list($key, $locale) = explode(':', $key);
        } else {
            $locale = $this->locale();
        }

        if ($this->isTranslationAttribute($key)) {
            if ($this->getTranslation($locale) === null) {
                return parent::getAttribute($key);
            }

            return $this->getTranslation($locale)->$key;
        }

        return parent::getAttribute($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function setAttribute($key, $value)
    {
        if (str_contains($key, ':')) {
            list($key, $locale) = explode(':', $key);
        } else {
            $locale = $this->locale();
        }

        if ($this->isTranslationAttribute($key)) {
            $this->getTranslationOrNew($locale)->$key = $value;
        } else {
            return parent::setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            if (count($this->getDirty()) > 0) {
                // If $this->exists and dirty, parent::save() has to return true. If not,
                // an error has occurred. Therefore we shouldn't save the translations.
                if (parent::save($options)) {
                    return $this->saveTranslations();
                }

                return false;
            } else {
                // If $this->exists and not dirty, parent::save() skips saving and returns
                // false. So we have to save the translations
                if ($saved = $this->saveTranslations()) {
                    $this->fireModelEvent('saved', false);
                    $this->fireModelEvent('updated', false);
                }

                return $saved;
            }
        } elseif (parent::save($options)) {
            // We save the translations only if the instance is saved in the database.
            return $this->saveTranslations();
        }

        return false;
    }

    /**
     * @param array $attributes
     *
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        foreach ($attributes as $key => $values) {
            if ($this->isTranslationAttribute($key)) {
                foreach($values as $locale => $translationValue) {
                    $translationModel = $this->getTranslationOrNew($locale);
                    if ($this->alwaysFillable() || $translationModel->isFillable($key)) {
                        $translationModel->$key = $translationValue;
                    } else {
                        throw new MassAssignmentException($key);
                    }
                }
                unset($attributes[$key]);
            } elseif ($this->isKeyALocale($key)) {
                $translationModel = $this->getTranslationOrNew($key);
                foreach ($values as $translationAttribute => $translationValue) {
                    if ($this->alwaysFillable() || $translationModel->isFillable($translationAttribute)) {
                        $translationModel->$translationAttribute = $translationValue;
                    } elseif ($totallyGuarded) {
                        throw new MassAssignmentException($key);
                    }
                }
                unset($attributes[$key]);
            }
        }

        return parent::fill($attributes);
    }

    /**
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function getTranslationOrNew($locale)
    {
        if (($translation = $this->getTranslation($locale, false)) === null) {
            $translation = $this->getNewTranslation($locale);
        }

        return $translation;
    }

    /**
     * @param string $key
     */
    private function getTranslationByLocaleKey($key)
    {
        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $key) {
                return $translation;
            }
        }

        return;
    }

    /**
     * @param null $locale
     *
     * @return string
     */
    private function getFallbackLocale($locale = null)
    {
        if ($locale && $this->isLocaleCountryBased($locale)) {
            if ($fallback = $this->getLanguageFromCountryBasedLocale($locale)) {
                return $fallback;
            }
        }

        return App::make('config')->get('translatable.fallback_locale');
    }

    /**
     * @param $locale
     *
     * @return bool
     */
    private function isLocaleCountryBased($locale)
    {
        return strpos($locale, $this->getLocaleSeparator()) !== false;
    }

    /**
     * @param $locale
     *
     * @return string
     */
    private function getLanguageFromCountryBasedLocale($locale)
    {
        $parts = explode($this->getLocaleSeparator(), $locale);

        return array_get($parts, 0);
    }

    /**
     * @return bool|null
     */
    private function useFallback()
    {
        if (isset($this->useTranslationFallback) && $this->useTranslationFallback !== null) {
            return $this->useTranslationFallback;
        }

        return App::make('config')->get('translatable.use_fallback');
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function isTranslationAttribute($key)
    {
        return in_array($key, $this->translatedAttributes);
    }

    /**
     * @param string $key
     *
     * @return bool
     *
     * @throws \Iginikolaev\Translatable\Exception\LocalesNotDefinedException
     */
    protected function isKeyALocale($key)
    {
        $locales = $this->getLocales();

        return in_array($key, $locales);
    }

    /**
     * @return array
     *
     * @throws \Iginikolaev\Translatable\Exception\LocalesNotDefinedException
     */
    public function getLocales()
    {
        $localesConfig = (array)App::make('config')->get('translatable.locales');

        if (empty($localesConfig)) {
            throw new LocalesNotDefinedException('Please make sure you have run "php artisan config:publish dimsav/laravel-translatable" ' .
                ' and that the locales configuration is defined.');
        }

        $locales = [];
        foreach ($localesConfig as $key => $locale) {
            if (is_array($locale)) {
                $locales[] = $key;
                foreach ($locale as $countryLocale) {
                    $locales[] = $key . $this->getLocaleSeparator() . $countryLocale;
                }
            } else {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * @return string
     */
    protected function getLocaleSeparator()
    {
        return App::make('config')->get('translatable.locale_separator', '-');
    }

    /**
     * @return bool
     */
    protected function saveTranslations()
    {
        $saved = true;
        foreach ($this->translations as $translation) {
            if ($saved && $this->isTranslationDirty($translation)) {
                $translation->setAttribute($this->getRelationKey(), $this->getKey());
                $saved = $translation->save();
            }
        }

        return $saved;
    }

    

    /**
     * @param \Illuminate\Database\Eloquent\Model $translation
     *
     * @return bool
     */
    protected function isTranslationDirty(Model $translation)
    {
        $dirtyAttributes = $translation->getDirty();
        unset($dirtyAttributes[$this->getLocaleKey()]);

        return count($dirtyAttributes) > 0;
    }

    /**
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getNewTranslation($locale)
    {
        $modelName = $this->getTranslationModelName();
        $translation = new $modelName();
        $translation->setAttribute($this->getLocaleKey(), $locale);
        $this->translations->add($translation);

        return $translation;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function __isset($key)
    {
        return ($this->isTranslationAttribute($key) || parent::__isset($key));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeTranslatedIn(Builder $query, $locale = null)
    {
        $locale = $locale ? : $this->locale();

        return $query->whereHas('translations', function (Builder $q) use ($locale) {
            $q->where($this->getLocaleKey(), '=', $locale);
        });
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeNotTranslatedIn(Builder $query, $locale = null)
    {
        $locale = $locale ? : $this->locale();

        return $query->whereDoesntHave('translations', function (Builder $q) use ($locale) {
            $q->where($this->getLocaleKey(), '=', $locale);
        });
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeTranslated(Builder $query)
    {
        return $query->has('translations');
    }

    /**
     * Adds scope to get a list of translated attributes, using the current locale.
     *
     * Example usage: Country::listsTranslations('name')->get()->toArray()
     * Will return an array with items:
     *  [
     *      'id' => '1',                // The id of country
     *      'name' => 'Griechenland'    // The translated name
     *  ]
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $translationField
     */
    public function scopeListsTranslations(Builder $query, $translationField)
    {
        $withFallback = $this->useFallback();
        $translationTable = $this->getTranslationsTable();
        $localeKey = $this->getLocaleKey();

        $query
            ->select($this->getTable() . '.' . $this->getKeyName(),
                $translationTable . '.' . $translationField)
            ->leftJoin($translationTable, $translationTable . '.' . $this->getRelationKey(), '=',
                $this->getTable() . '.' . $this->getKeyName())
            ->where($translationTable . '.' . $localeKey, $this->locale());
        if ($withFallback) {
            $query->orWhere(function (Builder $q) use ($translationTable, $localeKey) {
                $q->where($translationTable . '.' . $localeKey, $this->getFallbackLocale())
                    ->whereNotIn($translationTable . '.' . $this->getRelationKey(),
                        function (QueryBuilder $q) use ($translationTable, $localeKey) {
                            $q->select($translationTable . '.' . $this->getRelationKey())
                                ->from($translationTable)
                                ->where($translationTable . '.' . $localeKey, $this->locale());
                        });
            });
        }
    }

    /**
     * This scope eager loads the translations for the default and the fallback locale only.
     * We can use this as a shortcut to improve performance in our application.
     *
     * @param Builder $query
     */
    public function scopeWithTranslation(Builder $query)
    {
        $query->with([
            'translations' => function ($query) {
                $query->where($this->getTranslationsTable() . '.' . $this->getLocaleKey(),
                    $this->locale());

                if ($this->useFallback()) {
                    return $query->orWhere($this->getTranslationsTable() . '.' . $this->getLocaleKey(),
                        $this->getFallbackLocale());
                }
            },
        ]);
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $key
     * @param string $value
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeWhereTranslation(Builder $query, $key, $value, $locale = null)
    {
        return $query->whereHas('translations',
            function (Builder $query) use ($key, $value, $locale) {
                $query->where($this->getTranslationsTable() . '.' . $key, $value);
                if ($locale) {
                    $query->where($this->getTranslationsTable() . '.' . $this->getLocaleKey(),
                        $locale);
                }
            });
    }

    /**
     * This scope filters results by checking the translation fields.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $key
     * @param string $value
     * @param string $locale
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function scopeWhereTranslationLike(Builder $query, $key, $value, $locale = null)
    {
        return $query->whereHas('translations',
            function (Builder $query) use ($key, $value, $locale) {
                $query->where($this->getTranslationsTable() . '.' . $key, 'LIKE', $value);

                if ($locale) {
                    $query->where($this->getTranslationsTable() . '.' . $this->getLocaleKey(),
                        'LIKE', $locale);
                }
            });
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $attributes = parent::toArray();

        $hiddenAttributes = $this->getHidden();

        foreach ($this->translatedAttributes as $field) {
            if (in_array($field, $hiddenAttributes)) {
                continue;
            }

            if ($translations = $this->getTranslation()) {
                $attributes[$field] = $translations->$field;
            }
        }

        return $attributes;
    }

    /**
     * @return bool
     */
    private function alwaysFillable()
    {
        return App::make('config')->get('translatable.always_fillable', false);
    }

    /**
     * @return string
     */
    private function getTranslationsTable()
    {
        return App::make($this->getTranslationModelName())->getTable();
    }

    /**
     * @return string
     */
    protected function locale()
    {
        return App::make('config')->get('translatable.locale')
            ? : App::make('translator')->getLocale();
    }

    protected function _addTranslationColumns(Builder $builder, $localeTable, $fallbackTable)
    {
        $columns = [];
        $originalColumns = $builder->getQuery()->columns;
        if ($originalColumns === null) {
            $columns[] = $this->getTable() . '.*';
            foreach ($this->translatedAttributes as $_name) {
                $columns[] = $this->_getTranslationColumn($localeTable, $fallbackTable, $_name);
            }
        } else {
            foreach ($originalColumns as $_name) {
                $columns[] = $this->isTranslationAttribute($_name) ?
                    $this->_getTranslationColumn($localeTable, $fallbackTable, $_name) :
                    $_name;
            }
        }

        $builder->getQuery()->select($columns);
    }

    /**
     * @param Builder $builder
     * @param string $locale
     *
     * @return string
     */
    protected function _addTranslationJoin(Builder $builder, $locale)
    {
        $translationModelName = $this->getTranslationModelName();
        $translationModel = new $translationModelName();

        $tableAbbr = '_t' . $locale;

        $builder->leftJoin(
            $translationModel->getTable() . ' as ' . $tableAbbr,
            function ($join) use ($tableAbbr, $locale) {
                $join->on(
                    $tableAbbr . '.' . $this->getRelationKey()
                    , '='
                    , $this->getTable() . '.' . $this->getKeyName()
                )->where(
                    $tableAbbr . '.' . $this->getLocaleKey()
                    , '='
                    , $locale
                );
            }
        );

        return $tableAbbr;
    }

    /**
     * @param string $localeTable
     * @param string $fallbackTable
     * @param string $name
     *
     * @return \Illuminate\Database\Query\Expression
     */
    protected function _getTranslationColumn($localeTable, $fallbackTable, $name)
    {
        return \DB::raw('IFNULL(' . $localeTable . '.' . $name . ', ' . $fallbackTable . '.' . $name . ') as ' . $name);
    }

    protected function _addTranslationWheres(Builder $builder, $localeTable, $fallbackTable)
    {
        $query = $builder->getQuery();
        if (!$query->wheres) {
            return;
        }

        $bindings = $query->getRawBindings()['where'];

        foreach ($query->wheres as $k => $_where) {
            if (!empty($_where['column'])
                && $this->isTranslationAttribute($_where['column'])
            ) {
                $bindingKey = array_keys($bindings, $_where['value'])[0];
                unset($bindings[$bindingKey], $query->wheres[$k]);
                $query->setBindings(array_values($bindings), 'where');

                $query->whereNested(function (QueryBuilder $query) use (
                    $_where,
                    $localeTable,
                    $fallbackTable
                ) {
                    $query->where(
                        $localeTable . '.' . $_where['column']
                        , $_where['operator']
                        , $_where['value']
                        , $_where['boolean']
                    );
                    $query->where(
                        $fallbackTable . '.' . $_where['column']
                        , $_where['operator']
                        , $_where['value']
                        , 'or'
                    );
                });
            }
        }
    }

    /**
     * Inner join with the translation table
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param null $locale
     *
     * @return $this
     */
    public static function scopeJoinTranslation(Builder $builder, $locale = null)
    {
        $instance = new static;

        $locale = is_null($locale) ? $instance->locale() : $locale;
        $fallbackLocale = $instance->getFallbackLocale();

        $localeTable = $instance->_addTranslationJoin($builder, $locale);
        $fallbackTable = $instance->_addTranslationJoin($builder, $fallbackLocale);

        $instance->_addTranslationColumns($builder, $localeTable, $fallbackTable);
        $instance->_addTranslationWheres($builder, $localeTable, $fallbackTable);

        return $builder;
    }

    /**
     * Check if translation was with a inner join
     *
     * @return bool
     */
    private function isJoinTranslated()
    {
        foreach ($this->translatedAttributes as $translatedAttribute) {
            if (isset($this->original[$translatedAttribute]) && !empty($this->original[$translatedAttribute])) {
                return true;
            }
        }

        return false;
    }
}
