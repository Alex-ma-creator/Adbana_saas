<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Cobro extends Model
{
    use BelongsToRestaurante;

    protected $table = 'cobros';

    protected $guarded = ['id'];
}
