<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCustodianAttachment extends Model
{
    protected $table = 'inventory_custodian_attachments';

    protected $fillable = ['ics_id', 'filename', 'filepath', 'filetype', 'filesize'];

    public function slip()
    {
        return $this->belongsTo(InventoryCustodianSlip::class, 'ics_id', 'id');
    }
}