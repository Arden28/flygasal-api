<?php

namespace App\Models\Settings;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'site_name',
        'default_currency',
        'timezone',
        'language',
        'login_attemps',
        'email_notification',
        'sms_notification',
        'booking_confirmation_email',
        'booking_confirmation_sms',
    ];
}
