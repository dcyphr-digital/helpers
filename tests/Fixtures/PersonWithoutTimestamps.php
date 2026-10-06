<?php

namespace DcyphrDigital\Helpers\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class PersonWithoutTimestamps extends Model
{
    public $timestamps = false;

    protected $table = 'people_without_timestamps';

    protected $guarded = [];
}
