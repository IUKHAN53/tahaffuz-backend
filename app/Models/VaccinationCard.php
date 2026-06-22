<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VaccinationCard extends Model
{
    protected $fillable = [
        'device_id',
        'worker_id',
        'child_name',
        'sex',
        'date_of_birth',
        'father_name',
        'mother_name',
        'card_number',
        'district',
        'town',
        'union_council',
        'vaccines',
        'next_due_date',
        'raw_extract',
        'image_path',
    ];

    protected $casts = [
        'vaccines' => 'array',
        'raw_extract' => 'array',
    ];
}
