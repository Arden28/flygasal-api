<?php

namespace App\Models\Flights;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;


    protected $guarded = [];

    protected $casts = [
        'booking_date'          => 'datetime',
        'last_void_time'        => 'datetime',
        'ticket_issued_payload' => 'array',
        'baggage_info'          => 'array',
        'flights'               => 'array',
        'segments'              => 'array',
        'passengers'            => 'array',
        'permit_void'           => 'boolean',
        'adt_fare'              => 'decimal:2',
        'adt_tax'               => 'decimal:2',
        'chd_fare'              => 'decimal:2',
        'chd_tax'               => 'decimal:2',
        'total_amount'          => 'decimal:2',
        'total_fare'            => 'decimal:2',
        'total_tax'             => 'decimal:2',
        'void_service_fee'      => 'decimal:2',
        'agent_fee'             => 'decimal:2',
    ];

    // Relationships
    public function passengers()
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function segments()
    {
        return $this->hasMany(BookingSegment::class);
    }

    // Convenience
    public function segmentTickets()
    {
        return $this->hasManyThrough(
            BookingSegmentTicket::class,
            BookingSegment::class,
            'booking_id',       // FK on segments
            'booking_segment_id',
            'id',
            'id'
        );
    }

    /**
     * Get the user that owns the booking.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions associated with the booking.
     *
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
