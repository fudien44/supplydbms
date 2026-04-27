<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferAttachment extends Model
{
    use HasFactory;

    protected $table = 'inventory_transfer_attachments';

    protected $fillable = ['transfer_report_id', 'filename', 'filepath', 'filetype', 'filesize'];

    public function transferReport()
    {
        return $this->belongsTo(InventoryTransferReport::class, 'transfer_report_id', 'id');
    }
}