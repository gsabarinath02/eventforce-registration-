<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSetting extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'location_details' => 'array',
            'payment_providers' => 'array',
        ];
    }
}
