<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebinarRegistration extends Model
{
    public const SOURCES = ['website', 'admin'];

    protected $fillable = [
        'webinar',
        'user_id',
        'name',
        'phone',
        'source',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Registers the phone for the webinar, or returns the existing registration. */
    public static function register(string $webinar, string $name, string $phone, string $source): array
    {
        $existing = self::where('webinar', $webinar)->where('phone', $phone)->first();
        if ($existing) {
            return [$existing, false];
        }

        $registration = self::create([
            'webinar' => $webinar,
            'user_id' => User::where('phone_number', $phone)->value('id'),
            'name' => $name,
            'phone' => $phone,
            'source' => $source,
        ]);

        return [$registration, true];
    }

    public static function normalizePhone(string $phone): string
    {
        // Accept Persian/Arabic digits from mobile keyboards.
        $phone = strtr($phone, array_combine(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ));
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '0'.$digits;
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'webinar' => $this->webinar,
            'name' => $this->name,
            'phone' => $this->phone,
            'source' => $this->source,
            'userId' => $this->user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
