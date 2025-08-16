<?php

namespace App\Models\Flights;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingSegmentTicket extends Model
{
    use HasFactory;
    use HasFactory;

    protected $guarded = [];

    public function segment()
    {
        return $this->belongsTo(BookingSegment::class, 'booking_segment_id');
    }

    public function passenger()
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }
}
