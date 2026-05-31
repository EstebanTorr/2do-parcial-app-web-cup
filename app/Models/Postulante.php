<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\CalculaPromedio;

class Postulante extends Model
{
    use CalculaPromedio;

    protected $table = 'postulante';

    protected $fillable = [
        'usuario_id', 'convocatoria_id', 'pre_registro_id',
        'codigo_estudiante', 'ci', 'nombre', 'apellido',
        'fecha_nacimiento', 'sexo', 'email', 'telefono',
        'direccion', 'ciudad', 'colegio_nombre',
        'carrera_pref_1_id', 'carrera_pref_2_id',
        'turno_asignado', 'estado',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    // ──────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────

    public function carreraPref1(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_pref_1_id');
    }

    public function carreraPref2(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_pref_2_id');
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function grupoPostulante(): HasOne
    {
        return $this->hasOne(GrupoPostulante::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function resultadoFinal(): HasOne
    {
        return $this->hasOne(ResultadoFinal::class);
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    /** Nombre completo */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    /** Iniciales para el avatar */
    public function getInicialesAttribute(): string
    {
        return strtoupper(substr($this->nombre, 0, 1) . substr($this->apellido, 0, 1));
    }

    /** ¿Tiene pago validado? */
    public function tienePagoValidado(): bool
    {
        return $this->pagos()->where('estado', 'VALIDADO')->exists();
    }
}