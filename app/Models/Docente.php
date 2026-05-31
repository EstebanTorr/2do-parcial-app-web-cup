<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Docente extends Model
{
    protected $table = 'docente';

    protected $fillable = [
        'usuario_id',
        'nombre',
        'apellido',
        'ci',
        'email',
        'telefono',
        'especialidad',
        'estado',
    ];

    // ──────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────

    /** Un docente es un usuario */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Un docente tiene muchas materias */
    public function materias(): BelongsToMany
    {
        return $this->belongsToMany(
            Materia::class,
            'docente_materia',
            'docente_id',
            'materia_id'
        );
    }

    /** Un docente está en muchos grupos */
    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(
            Grupo::class,
            'grupo_docente',
            'docente_id',
            'grupo_id'
        );
    }

    /** Un docente registra muchas notas */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    /** Nombre completo */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    /** Iniciales para avatar */
    public function getInicialesAttribute(): string
    {
        return strtoupper(substr($this->nombre, 0, 1) . substr($this->apellido, 0, 1));
    }

    /** ¿Docente activo? */
    public function esActivo(): bool
    {
        return $this->estado === 'ACTIVO';
    }
}
