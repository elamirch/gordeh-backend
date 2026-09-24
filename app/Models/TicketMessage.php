<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $fillable = [
        'ticket_id',
        'author',
        'author_id',
        'text',
        'file_url',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    // Named authorUser (not author) since `author` is already a plain string column
    // ('user'|'agent'|'note') — a same-named relation method would be unreachable.
    public function authorUser()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
