<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ref_supunit: supply units used as the ITR "From" (same list as the C# PTR page)
class SupUnit extends Model
{
    protected $table = 'ref_supunit';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $guarded = [];
}