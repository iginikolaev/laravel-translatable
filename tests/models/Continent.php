<?php namespace Iginikolaev\Translatable\Test\Model;

use Iginikolaev\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * A test class that has no required properties.
 */
class Continent extends Eloquent
{
    use Translatable;

    public $translatedAttributes = ['name'];
}
