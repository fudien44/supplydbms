<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyAcknowledgementAttachment extends Model
{
    protected $table = 'property_acknowledgement_attachments';

    protected $fillable = ['par_id', 'filename', 'filepath', 'filetype', 'filesize'];

    public function receipt()
    {
        return $this->belongsTo(PropertyAcknowledgementReceipt::class, 'par_id', 'id');
    }
}