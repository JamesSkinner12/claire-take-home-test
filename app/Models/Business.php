<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory;

    protected $table = "businesses";

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'external_id',
        'deduction_percentage',
        'enabled'
    ];
    
    /**
     * Fetch the users associated with the business.
     *
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_businesses');
    }
    
    /**
     * Fetch the pay items associated with the business.
     *
     * @return HasMany
     */
    public function payItems(): HasMany
    {
        return $this->hasMany(PayItem::class, 'business_id');
    }
}
