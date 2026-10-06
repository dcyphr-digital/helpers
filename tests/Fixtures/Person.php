<?php

namespace DcyphrDigital\Helpers\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'registered_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
