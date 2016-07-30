<?php namespace Iginikolaev\Translatable\Test\Model;

use Iginikolaev\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model as Eloquent;

class Vegetable extends Eloquent
{
    use Translatable;

    protected $primaryKey = 'vegetable_identity';

    public $translatedAttributes = ['name'];

}
