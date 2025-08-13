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
        'order_num',
        'pnr',
        'solution_id',
        'fare_type',
        'currency',
        'adt_fare',
        'adt_tax',
        'chd_fare',
        'chd_tax',
        'infants',
        'adults',
        'children',
        'plating_carrier',
        'baggage_info',
        'flights',
        'segments',
        'passengers',
        'agent_fee',
        'total_amount',
        'contact_name',
        'contact_email',
        'contact_phone',
        'booking_date',
        'status'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'baggage_info' => 'array',
        'flights' => 'array',
        'segments' => 'array',
        'passengers' => 'array',
        'booking_date' => 'datetime',
        'agent_fee' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

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
