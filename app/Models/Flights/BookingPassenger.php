<?php

namespace App\Models\Flights;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingPassenger extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'birthday'                => 'date',
        'card_expired_date'       => 'date',
        'associated_passenger_index' => 'integer',
        'passenger_index'         => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function segmentTickets()
    {
        return $this->hasMany(BookingSegmentTicket::class);
    }

    // Helpful scope to fetch by passengerIndex quickly
    public function scopeIdx($q, int $idx)
    {
        return $q->where('passenger_index', $idx);
    }
}
