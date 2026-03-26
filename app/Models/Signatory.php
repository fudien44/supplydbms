<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signatory extends Model
{
    protected $table = 'ref_signatory';
    protected $primaryKey = 'sign_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $guarded = [];

    public function scopeActive($query)
    {
        return $query->where('sign_void', 0)->where('sign_isactive', 1);
    }

    public function scopeApprovedBy($query)
    {
        return $query->active()->where('sign_categ', 'Approved By')->select('sign_id', 'sign_name'); // ✅ only these columns
    }

    public function scopeIssuedBy($query)
    {
        return $query->active()->where('sign_categ', 'Issued By')->select('sign_id', 'sign_name'); // ✅ only these columns
    }
}
