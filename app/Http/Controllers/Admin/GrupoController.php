<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use App\Models\Convocatoria;
use App\Models\Postulante;
use App\Models\Docente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    /**
     * CU12 - Listar grupos de una convocatoria
     */
    public function index(Request $request)
    {
        // Obtener convocatoria activa o especificada
        $convocatoriaId = $request->query('convocatoria_id');

        if ($convocatoriaId) {
            $convocatoria = Convocatoria::findOrFail($convocatoriaId);
            $grupos = $convocatoria->grupos()->with(['postulantes', 'docentes'])->get();
        } else {
            // Si no hay especificada, mostrar la activa
            $convocatoria = Convocatoria::where('estado', 'ACTIVA')->first();
            $grupos = $convocatoria ? $convocatoria->grupos()->with(['postulantes', 'docentes'])->get() : [];
        }

        $convocatorias = Convocatoria::whereIn('estado', ['ACTIVA', 'PLANIFICADA'])->get();

        return view('admin.grupos.index', compact('grupos', 'convocatoria', 'convocatorias', 'convocatoriaId'));
    }

    /**
     * CU12 - Mostrar detalles de un grupo y asignar postulantes/docentes
     */
    public function show(Grupo $grupo)
    {
        $grupo->load(['postulantes', 'docentes', 'convocatoria']);

        // Postulantes APROBADOS de esta convocatoria que aún no están en NINGÚN grupo
        $postulanteYaAsignados = DB::table('grupo_postulante')->pluck('postulante_id');
        $postulantesSinGrupo = Postulante::where('convocatoria_id', $grupo->convocatoria_id)
            ->where('estado', 'APROBADO')
            ->whereNotIn('id', $postulanteYaAsignados)
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->get();

        // Docentes ACTIVOS que aún no están asignados a este grupo
        $docentesDisponibles = Docente::where('estado', 'ACTIVO')
            ->whereNotIn('id', $grupo->docentes()->pluck('docente_id'))
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->get();

        // Estadísticas para el panel
        $totalAprobados  = Postulante::where('convocatoria_id', $grupo->convocatoria_id)
            ->where('estado', 'APROBADO')->count();
        $totalAsignados  = DB::table('grupo_postulante')
            ->join('grupo', 'grupo.id', '=', 'grupo_postulante.grupo_id')
            ->where('grupo.convocatoria_id', $grupo->convocatoria_id)
            ->count();

        return view('admin.grupos.show', compact(
            'grupo',
            'postulantesSinGrupo',
            'docentesDisponibles',
            'totalAprobados',
            'totalAsignados'
        ));
    }

    /**
     * CU12 - Asignar postulante a un grupo
     */
    public function asignarPostulante(Request $request, Grupo $grupo)
    {
        $validated = $request->validate([
            'postulante_id' => 'required|exists:postulante,id',
        ]);

        $postulante = Postulante::findOrFail($validated['postulante_id']);

        // CU: Verificar que el postulante tiene estado APROBADO
        if ($postulante->estado !== 'APROBADO') {
            $msg = "El postulante {$postulante->nombre_completo} no tiene estado APROBADO (estado actual: {$postulante->estado}). Solo se pueden asignar postulantes aprobados.";
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // CU: Verificar que el postulante pertenece a la misma convocatoria
        if ($postulante->convocatoria_id !== $grupo->convocatoria_id) {
            $msg = 'El postulante no pertenece a la convocatoria de este grupo.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // CU: Verificar que el postulante no esté ya asignado a algún grupo
        $yaEnGrupo = DB::table('grupo_postulante')->where('postulante_id', $postulante->id)->exists();
        if ($yaEnGrupo) {
            $msg = "El postulante {$postulante->nombre_completo} ya está asignado a un grupo.";
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 409);
            }
            return back()->with('error', $msg);
        }

        // CU: Verificar capacidad máxima (límite: 70 estudiantes por grupo)
        $capacidadEfectiva = min($grupo->capacidad_maxima, 70);
        $ocupados = $grupo->postulantes()->count();
        if ($ocupados >= $capacidadEfectiva) {
            $msg = "El grupo {$grupo->numero_grupo} ha alcanzado su capacidad máxima de {$capacidadEfectiva} estudiantes. Libera cupos antes de continuar.";
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 409);
            }
            return back()->with('error', $msg);
        }

        // Asignar postulante al grupo
        $grupo->postulantes()->attach($postulante->id);

        // Registrar en log de actividad
        DB::table('log_actividad')->insert([
            'usuario_id'     => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email'  => Auth::user()->email,
            'accion'         => 'asignacion_creada',
            'descripcion'    => "Postulante {$postulante->nombre_completo} (CI: {$postulante->ci}) asignado al grupo {$grupo->numero_grupo}",
            'ip'             => $request->ip(),
            'modulo'         => 'grupos',
            'resultado'      => 'ok',
            'fecha_hora'     => now(),
        ]);

        $msg = "Postulante {$postulante->nombre_completo} asignado al Grupo {$grupo->numero_grupo} exitosamente.";
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }
        return back()->with('success', $msg);
    }

    /**
     * CU12 - Desasignar postulante de un grupo
     */
    public function desasignarPostulante(Request $request, Grupo $grupo)
    {
        $validated = $request->validate([
            'postulante_id' => 'required|exists:postulante,id',
        ]);

        $grupo->postulantes()->detach($validated['postulante_id']);

        DB::table('log_actividad')->insert([
            'usuario_id' => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email' => Auth::user()->email,
            'accion' => 'asignacion_eliminada',
            'descripcion' => "Postulante desasignado del grupo {$grupo->numero_grupo}",
            'ip' => request()->ip(),
            'modulo' => 'grupos',
            'resultado' => 'ok',
            'fecha_hora' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Postulante desasignado',
            ]);
        }
        return back()->with('success', 'Postulante desasignado exitosamente');
    }

    /**
     * CU12 - Asignar docente a un grupo
     */
    public function asignarDocente(Request $request, Grupo $grupo)
    {
        $validated = $request->validate([
            'docente_id' => 'required|exists:docente,id',
        ]);

        $docente = Docente::findOrFail($validated['docente_id']);

        // CU: Verificar que el docente tiene estado ACTIVO (contratado/disponible)
        if ($docente->estado !== 'ACTIVO') {
            $msg = "El docente {$docente->nombre_completo} no está disponible (estado: {$docente->estado}). Solo se pueden asignar docentes con estado ACTIVO.";
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // CU: Verificar que el docente no esté ya asignado a este grupo
        if ($grupo->docentes()->where('docente_id', $docente->id)->exists()) {
            $msg = "El docente {$docente->nombre_completo} ya está asignado al Grupo {$grupo->numero_grupo}.";
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 409);
            }
            return back()->with('error', $msg);
        }

        // Asignar docente al grupo
        $grupo->docentes()->attach($docente->id);

        // Registrar en log de actividad
        DB::table('log_actividad')->insert([
            'usuario_id'     => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email'  => Auth::user()->email,
            'accion'         => 'asignacion_creada',
            'descripcion'    => "Docente {$docente->nombre_completo} asignado al grupo {$grupo->numero_grupo}",
            'ip'             => $request->ip(),
            'modulo'         => 'grupos',
            'resultado'      => 'ok',
            'fecha_hora'     => now(),
        ]);

        $msg = "Docente {$docente->nombre_completo} asignado al Grupo {$grupo->numero_grupo} exitosamente.";
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }
        return back()->with('success', $msg);
    }

    /**
     * CU12 - Desasignar docente de un grupo
     */
    public function desasignarDocente(Request $request, Grupo $grupo)
    {
        $validated = $request->validate([
            'docente_id' => 'required|exists:docente,id',
        ]);

        $grupo->docentes()->detach($validated['docente_id']);

        DB::table('log_actividad')->insert([
            'usuario_id' => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email' => Auth::user()->email,
            'accion' => 'asignacion_eliminada',
            'descripcion' => "Docente desasignado del grupo {$grupo->numero_grupo}",
            'ip' => request()->ip(),
            'modulo' => 'grupos',
            'resultado' => 'ok',
            'fecha_hora' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Docente desasignado',
            ]);
        }
        return back()->with('success', 'Docente desasignado exitosamente');
    }

    /**
     * CU13 - Ver docentes por grupo (API para consulta)
     */
    public function docentesPorGrupo(Grupo $grupo)
    {
        $docentes = $grupo->docentes()->get();

        return response()->json([
            'grupo' => $grupo->numero_grupo,
            'docentes' => $docentes->map(fn($d) => [
                'id' => $d->id,
                'nombre' => $d->nombre . ' ' . $d->apellido,
                'email' => $d->email,
                'especialidad' => $d->especialidad,
            ]),
        ]);
    }

    /**
     * Generar grupos automáticamente para una convocatoria
     */
    public function generar(Request $request)
    {
        $validated = $request->validate([
            'convocatoria_id' => 'required|exists:convocatoria,id',
            'cantidad' => 'required|integer|min:1|max:20',
            'capacidad' => 'required|integer|min:5|max:100',
            'turno' => 'required|string|in:MAÑANA,TARDE,NOCHE',
        ]);

        $convocatoria = Convocatoria::findOrFail($validated['convocatoria_id']);

        if ($convocatoria->grupos()->exists()) {
            return back()->with('error', 'Esta convocatoria ya tiene grupos creados. Límpialos primero si deseas regenerarlos.');
        }

        // Crear los grupos
        for ($i = 1; $i <= $validated['cantidad']; $i++) {
            Grupo::create([
                'convocatoria_id' => $convocatoria->id,
                'numero_grupo' => $i,
                'turno' => $validated['turno'],
                'estado' => 'ACTIVO',
                'capacidad_maxima' => $validated['capacidad'],
                'descripcion' => "Grupo {$i} - {$convocatoria->nombre}",
            ]);
        }

        // Registrar en log
        DB::table('log_actividad')->insert([
            'usuario_id' => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email' => Auth::user()->email,
            'accion' => 'grupo_creado',
            'descripcion' => "Creados automáticamente {$validated['cantidad']} grupos para la convocatoria {$convocatoria->nombre}",
            'ip' => $request->ip(),
            'modulo' => 'grupos',
            'resultado' => 'ok',
            'fecha_hora' => now(),
        ]);

        return back()->with('success', "¡Se crearon {$validated['cantidad']} grupos exitosamente!");
    }

    /**
     * Eliminar todos los grupos de una convocatoria y limpiar sus asignaciones
     */
    public function limpiar(Request $request)
    {
        $validated = $request->validate([
            'convocatoria_id' => 'required|exists:convocatoria,id',
        ]);

        $convocatoria = Convocatoria::findOrFail($validated['convocatoria_id']);

        $gruposIds = $convocatoria->grupos()->pluck('id');

        if ($gruposIds->isEmpty()) {
            return back()->with('error', 'No hay grupos creados para esta convocatoria.');
        }

        // Obtener IDs de postulantes vinculados a estos grupos antes de borrar las relaciones
        $postulantesIds = DB::table('grupo_postulante')->whereIn('grupo_id', $gruposIds)->pluck('postulante_id');

        // Eliminar relaciones primero
        DB::table('grupo_postulante')->whereIn('grupo_id', $gruposIds)->delete();
        DB::table('grupo_docente')->whereIn('grupo_id', $gruposIds)->delete();
        
        // Eliminar notas asociadas por postulante (usando columna postulante_id existente)
        if ($postulantesIds->isNotEmpty()) {
            DB::table('nota')->whereIn('postulante_id', $postulantesIds)->delete();
        }

        // Eliminar los grupos
        $convocatoria->grupos()->delete();

        // Registrar en log
        DB::table('log_actividad')->insert([
            'usuario_id' => Auth::id(),
            'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
            'usuario_email' => Auth::user()->email,
            'accion' => 'grupo_eliminado',
            'descripcion' => "Se eliminaron todos los grupos y sus asignaciones de la convocatoria {$convocatoria->nombre}",
            'ip' => $request->ip(),
            'modulo' => 'grupos',
            'resultado' => 'ok',
            'fecha_hora' => now(),
        ]);

        return back()->with('success', 'Se eliminaron todos los grupos y asignaciones correctamente.');
    }

    /**
     * Asignar postulantes automáticamente sin grupo
     */
    public function autoAsignar(Request $request)
    {
        $validated = $request->validate([
            'convocatoria_id' => 'required|exists:convocatoria,id',
        ]);

        $convocatoria = Convocatoria::findOrFail($validated['convocatoria_id']);
        $grupos = $convocatoria->grupos()->where('activo', true)->get();

        if ($grupos->isEmpty()) {
            return back()->with('error', 'Debes crear grupos activos primero para poder realizar la asignación automática.');
        }

        // CU: Solo postulantes con estado APROBADO y sin grupo asignado
        $postulantesAsignadosIds = DB::table('grupo_postulante')->pluck('postulante_id');
        $postulantesSinGrupo = Postulante::where('convocatoria_id', $convocatoria->id)
            ->where('estado', 'APROBADO')
            ->whereNotIn('id', $postulantesAsignadosIds)
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->get();

        if ($postulantesSinGrupo->isEmpty()) {
            return back()->with('error', 'No hay postulantes APROBADOS sin grupo para asignar.');
        }

        $asignadosCount  = 0;
        $noAsignadoCount = 0;

        foreach ($postulantesSinGrupo as $postulante) {
            $asignado = false;
            // CU: Respetar capacidad máxima de 70 por grupo
            foreach ($grupos as $grupo) {
                $capacidadEfectiva = min($grupo->capacidad_maxima, 70);
                $actual = $grupo->postulantes()->count();
                if ($actual < $capacidadEfectiva) {
                    $grupo->postulantes()->attach($postulante->id);
                    $asignadosCount++;
                    $asignado = true;
                    break;
                }
            }
            if (!$asignado) {
                $noAsignadoCount++;
            }
        }

        if ($asignadosCount > 0) {
            DB::table('log_actividad')->insert([
                'usuario_id'     => Auth::id(),
                'usuario_nombre' => Auth::user()->nombre . ' ' . Auth::user()->apellido,
                'usuario_email'  => Auth::user()->email,
                'accion'         => 'asignacion_masiva',
                'descripcion'    => "Distribución automática: {$asignadosCount} postulantes APROBADOS distribuidos en grupos de la convocatoria {$convocatoria->nombre}",
                'ip'             => $request->ip(),
                'modulo'         => 'grupos',
                'resultado'      => 'ok',
                'fecha_hora'     => now(),
            ]);

            $mensaje = "Se distribuyeron {$asignadosCount} postulantes aprobados exitosamente.";
            if ($noAsignadoCount > 0) {
                $mensaje .= " {$noAsignadoCount} postulante(s) no pudieron asignarse por falta de cupo (capacidad máxima 70 por grupo).";
            }
            return back()->with('success', $mensaje);
        }

        return back()->with('error', 'No se pudieron asignar postulantes. Verifique que los grupos tengan cupos disponibles (máximo 70 por grupo).');
    }
}
