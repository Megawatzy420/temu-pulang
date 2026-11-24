<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTranslation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * Product that owns this translation.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
