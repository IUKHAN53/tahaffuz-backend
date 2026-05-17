<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'language', 'is_default'];

    protected $casts = [
        'is_default' => 'bool',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}
