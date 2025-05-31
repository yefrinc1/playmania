<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResumenMensual extends Model
{
    use HasFactory;

    protected $table = 'resumen_mensual';

    protected $guarded = [];
}
