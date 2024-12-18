<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * This is section (IDN: unit kerja) for instance model. One section 
 * just belongs to one instance, but one instance has many sections.
 */
class InstanceSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'instance_id',
        'name'
    ];

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_has_sections')->withTimestamps();
    }
}
