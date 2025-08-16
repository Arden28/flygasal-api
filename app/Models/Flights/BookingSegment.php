<?php

namespace App\Models\Flights;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingSegment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'segment_no' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function tickets()
    {
        return $this->hasMany(BookingSegmentTicket::class);
    }

    // Helpful scope to fetch by segment_no quickly
    public function scopeNo($q, int $no)
    {
        return $q->where('segment_no', $no);
    }
}
