<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grupo extends Model
{
    protected $table = 'grupo';

    const UPDATED_AT = null;

    protected $fillable = [
        'convocatoria_id',
        'codigo_grupo',
        'turno',
        'activo',
        'capacidad',
        
        // Virtual fields to trigger mutators during mass assignment
        'numero_grupo',
        'capacidad_maxima',
        'estado',
    ];

    protected $casts = [
        'capacidad' => 'integer',
        'activo' => 'boolean',
    ];

    // ──────────────────────────────────────────
    // Mapeo Virtual (Accessors & Mutators)
    // ──────────────────────────────────────────

    public function getNumeroGrupoAttribute()
    {
        return $this->attributes['codigo_grupo'] ?? null;
    }

    public function setNumeroGrupoAttribute($value)
    {
        $this->attributes['codigo_grupo'] = $value;
    }

    public function getCapacidadMaximaAttribute()
    {
        return $this->attributes['capacidad'] ?? null;
    }

    public function setCapacidadMaximaAttribute($value)
    {
        $this->attributes['capacidad'] = $value;
    }

    public function getEstadoAttribute()
    {
        if (!isset($this->attributes['activo'])) {
            return 'INACTIVO';
        }
        return $this->attributes['activo'] ? 'ACTIVO' : 'INACTIVO';
    }

    public function setEstadoAttribute($value)
    {
        $this->attributes['activo'] = ($value === 'ACTIVO' || $value === true || $value === 1 || $value === '1');
    }

    // ──────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────

    /** Un grupo pertenece a una convocatoria */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    /** Un grupo tiene muchos postulantes (relación muchos-a-muchos) */
    public function postulantes(): BelongsToMany
    {
        return $this->belongsToMany(
            Postulante::class,
            'grupo_postulante',
            'grupo_id',
            'postulante_id'
        );
    }

    /** Un grupo tiene muchos docentes (relación muchos-a-muchos) */
    public function docentes(): BelongsToMany
    {
        return $this->belongsToMany(
            Docente::class,
            'grupo_docente',
            'grupo_id',
            'docente_id'
        );
    }

    /** Un grupo tiene muchas notas */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    /** Cantidad de postulantes en el grupo */
    public function countPostulantes(): int
    {
        return $this->postulantes()->count();
    }

    /** ¿Grupo lleno? */
    public function estaLleno(): bool
    {
        return $this->countPostulantes() >= $this->capacidad_maxima;
    }

    /** Espacios disponibles */
    public function espaciosDisponibles(): int
    {
        return max(0, $this->capacidad_maxima - $this->countPostulantes());
    }
}
