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

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'pkfare_booking_reference',
        'status',
        'total_price',
        'currency',
        'flight_details',
        'passenger_details',
        'contact_email',
        'contact_phone',
        'booking_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'flight_details' => 'array', // Cast to array for easy JSON handling
        'passenger_details' => 'array', // Cast to array for easy JSON handling
        'booking_date' => 'datetime',
        'total_price' => 'decimal:2',
    ];

    /**
     * Get the user that owns the booking.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(related:User::class);
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
