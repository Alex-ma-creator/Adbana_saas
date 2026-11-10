<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class CuentaMesa extends Model
{
    use BelongsToRestaurante;

    protected $table = 'cuentas_mesa';

    protected $guarded = ['id'];
}
