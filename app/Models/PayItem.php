<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'amount',
        'pay_rate',
        'hours',
        'external_id',
        'pay_date',
        'user_id',
        'business_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        //'user_id',
        //'business_id',
    ];

    /**
     * Fetch the user that owns the pay item.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Fetch the business that owns the pay item.
     *
     * @return BelongsTo
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
     
    /**
     * Returns the appropriate amount based off hours, pay rate, and business deduction.
     *
     * @param  Business $business
     * @param  float $hours
     * @param  float $payRate
     * @return float
     */
    public static function calculateAmount(Business $business, float $hours, float $payRate): float
    {
        $deduction = $business->deduction_percentage ?: 30;
        return round($hours * $payRate * ($deduction / 100), 2, PHP_ROUND_HALF_UP);
    }
}