<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'guest_phone',
        'guest_name',
        'guest_token_hash',
        'status',
        'agent_id',
        'agent_joined_at',
        'agent_typing_until',
        'last_read_message_id',
        'closed_at',
        'closed_by',
        'rating',
        'rating_note',
        'rated_at',
    ];

    // Never serialize the guest's credentials or phone by accident; every API shape is built
    // explicitly in ChatService.
    protected $hidden = [
        'guest_token_hash',
        'guest_phone',
    ];

    protected $casts = [
        'agent_joined_at' => 'datetime',
        'agent_typing_until' => 'datetime',
        'last_read_message_id' => 'integer',
        'closed_at' => 'datetime',
        'rated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function messages()
    {
        // id breaks ties between messages created within the same second.
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }
}
