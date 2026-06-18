<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $fillable = [
        'device_id',
        'name',
        'phone',
        'designation',
        'district',
        'town',
        'union_council',
    ];
}
