<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $fillable = [
        'knowledge_base_id', 'document_id', 'ordinal',
        'content', 'token_estimate', 'embedding', 'embedding_norm',
    ];

    protected $casts = [
        'embedding' => 'array',
        'embedding_norm' => 'float',
        'ordinal' => 'int',
        'token_estimate' => 'int',
    ];

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
