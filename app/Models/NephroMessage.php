<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NephroMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['consultation_id', 'sender_id', 'sender_role', 'kind', 'body', 'stored_file_id', 'meta', 'created_at'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function consultation()
    {
        return $this->belongsTo(NephroConsultation::class, 'consultation_id');
    }

    public function file()
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
