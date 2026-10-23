<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoManagerProposal extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['proposal' => 'array'];
}
