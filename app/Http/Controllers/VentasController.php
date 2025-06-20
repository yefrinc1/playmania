<?php

namespace App\Http\Controllers;

use App\Models\CodigoVerificacion;
use App\Models\CorreoJuego;
use App\Models\Movimientos;
use App\Models\Notificaciones;
use App\Models\ResumenMensual;
use App\Models\Ventas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VentasController extends Controller
{
    public function index(Request $request)
    {
        $juego = $request->input('juego');
        $cliente = $request->input('cliente');
        $correo = $request->input('correo');
        $fecha = $request->input('fecha');
        $mensajeEdit = $request->input('mensaje_edit', '');
    
        // Si no hay ningún filtro, no hacemos la consulta
        if (!$juego && !$cliente && !$correo && !$fecha) {
            $resultadoConsulta = Ventas::with('correoJuego')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
        } else {
            $resultadoConsulta = Ventas::with('correoJuego')
                ->when($juego, function ($query, $juego) {
                    return $query->whereHas('correoJuego', function ($q) use ($juego) {
                        $q->where('juego', 'like', "%$juego%");
                    });
                })
                ->when($cliente, function ($query, $cliente) {
                    $clienteSinEspacios = str_replace(' ', '', $cliente);
                    return $query->whereRaw("REGEXP_REPLACE(cliente, '[[:space:]]', '') LIKE ?", ["%$clienteSinEspacios%"]);
                })
                ->when($correo, function ($query, $correo) {
                    return $query->whereHas('correoJuego', function ($q) use ($correo) {
                        $q->where('correo', 'like', "%$correo%");
                    });
                })
                ->when($fecha, function ($query, $fecha) {
                    return $query->whereDate('created_at', $fecha);
                })
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }
    
        return Inertia::render('Ventas/Index', [
            'resultado_consulta' => $resultadoConsulta,
            'mensaje_edit' => $mensajeEdit,
            'filtros' => [
                'juego' => $juego,
                'cliente' => $cliente,
                'correo' => $correo,
                'fecha' => $fecha,
            ],
        ]);
    }    

    public function create(Request $request)
    {
        $cuentaJuego = $request->input('cuenta_juego');
        return Inertia::render('Ventas/Create', ["cuenta_juego" => $cuentaJuego]);
    }

    public function store(Request $request)
    {
        $cuentaJuego = [];
        $licencia = "";
        $hoy = Carbon::today();
        $mesActual = $hoy->month;
        $anioActual = $hoy->year;


        if ($request->tipo_cuenta == 'Primaria') {
            if ($request->consola == 'PS4') {
                $licencia = "primaria_ps4";

                $correoJuego = CorreoJuego::where('juego', $request->juego)
                ->where('primaria_ps4', '<', 2)
                ->where('primaria_ps5', '<=', 1)
                ->where('disponible', 1)
                ->orderBy('secundaria')
                ->orderBy('id')
                ->first();

                if ($correoJuego == null) {
                    $correoJuego = CorreoJuego::where('juego', $request->juego)
                    ->where('primaria_ps4', '<', 2)
                    ->where('disponible', 1)
                    ->orderBy('secundaria')
                    ->orderBy('id')
                    ->first();
                }
            } else if ($request->consola == 'PS5') {
                $licencia = "primaria_ps5";

                $correoJuego = CorreoJuego::where('juego', $request->juego)
                ->where('primaria_ps5', '<', 2)
                ->where('primaria_ps4', '<=', 1)
                ->where('disponible', 1)
                ->orderBy('secundaria')
                ->orderBy('id')
                ->first();

                if ($correoJuego == null) {
                    $correoJuego = CorreoJuego::where('juego', $request->juego)
                    ->where('primaria_ps5', '<', 2)
                    ->where('disponible', 1)
                    ->orderBy('secundaria')
                    ->orderBy('id')
                    ->first();
                }
            }
        } else if ($request->tipo_cuenta == 'Secundaria') {
            $licencia = "secundaria";
            if ($request->consola == 'PS4') {
                $correoJuego = CorreoJuego::where('juego', $request->juego)
                ->where('primaria_ps4', '=', 2)
                ->where('secundaria', '=', 0)
                ->where('disponible', 1)
                ->orderBy('id')
                ->first();
            } else if ($request->consola == 'PS5') {
                $correoJuego = CorreoJuego::where('juego', $request->juego)
                ->where('primaria_ps5', '=', 2)
                ->where('secundaria', '=', 0)
                ->where('disponible', 1)
                ->orderBy('id')
                ->first();
            }
        }

        if ($correoJuego != null) {
            $codigoArrayOficial = [];

            $codigoVerificacion = CodigoVerificacion::where('id_correo_juego', $correoJuego->id)
            ->where('disponible', 1)
            ->get();

            if ($codigoVerificacion->isEmpty()) {
                Ventas::create([
                    'id_correo_juego' => $correoJuego->id,
                    'cliente' => $request->cliente,
                    'tipo_cuenta' => $request->tipo_cuenta,
                    'consola' => $request->consola,
                    'precio' => $request->precio,
                    'medio_pago' => $request->medio_pago,
                    'moneda' => $request->moneda,
                    'id_usuario' => $request->id_usuario,
                ]);

                $disponibilidadAhora = $correoJuego->$licencia + 1;
                $correoJuego->update([$licencia => $disponibilidadAhora]);

                $cuentaDisponible = $correoJuego->primaria_ps4 + $correoJuego->primaria_ps5 + $correoJuego->secundaria >= 5;

                if ($cuentaDisponible == true) {
                    $correoJuego->update(["disponible" => 0]);
                }

                $agotadoJuego = $this->comprobarExistenciaJuego($request->tipo_cuenta, $request->consola, $request->juego);

                if ($agotadoJuego == null) {
                    Notificaciones::create([
                        'tipo' => 'agotado_juego',
                        'juego' => $request->juego,
                        'mensaje' => "Se agotaron las cuentas $request->tipo_cuenta para $request->consola",
                    ]);
                }

                $cuentaJuego["resultado"] = true;
                $cuentaJuego["ultimo_codigo"] = false;
                $cuentaJuego["juego"] = $correoJuego->juego;
                $cuentaJuego["tipo_cuenta"] = $request->tipo_cuenta;
                $cuentaJuego["consola"] = $request->consola;
                $cuentaJuego["correo"] = $correoJuego->correo;
                $cuentaJuego["contrasena"] = $correoJuego->contrasena;
                $cuentaJuego["codigo"] = "";
            } else {
                $codigosLibres = 0;
                foreach ($codigoVerificacion as $codigo) {
                    if ($codigo->respaldo == 0) {
                        $codigosLibres++;
    
                        if (empty($codigoArrayOficial)) {
                            $codigoArrayOficial = $codigo;
                        }
                    }
                }
    
                if ($codigosLibres > 0) {
                    Ventas::create([
                        'id_correo_juego' => $correoJuego->id,
                        'cliente' => $request->cliente,
                        'tipo_cuenta' => $request->tipo_cuenta,
                        'consola' => $request->consola,
                        'precio' => $request->precio,
                        'medio_pago' => $request->medio_pago,
                        'moneda' => $request->moneda,
                        'id_usuario' => $request->id_usuario,
                    ]);

                    $codigoArrayOficial->update(["disponible" => 0]);

                    $disponibilidadAhora = $correoJuego->$licencia + 1;
                    $correoJuego->update([$licencia => $disponibilidadAhora]);

                    $cuentaDisponible = $correoJuego->primaria_ps4 + $correoJuego->primaria_ps5 + $correoJuego->secundaria >= 5;

                    if ($cuentaDisponible == true) {
                        $correoJuego->update(["disponible" => 0]);
                    }

                    $agotadoJuego = $this->comprobarExistenciaJuego($request->tipo_cuenta, $request->consola, $request->juego);
                    if ($agotadoJuego == null) {
                        Notificaciones::create([
                            'tipo' => 'agotado_juego',
                            'juego' => $request->juego,
                            'mensaje' => "Se agotaron las cuentas $request->tipo_cuenta para $request->consola",
                        ]);
                    }

                    if ($codigosLibres == 1) {
                        $notificacionExiste = Notificaciones::where('id_correo_juego', $correoJuego->id)
                        ->where('tipo', 'crear_codigos')
                        ->where('mensaje', 'Se necesita crear codigos')
                        ->first();

                        if ($notificacionExiste == null) {
                            Notificaciones::create([
                                'id_correo_juego' => $correoJuego->id,
                                'tipo' => 'crear_codigos',
                                'mensaje' => 'Se necesita crear codigos',
                            ]);
                        }
                    }

                    $cuentaJuego["resultado"] = true;
                    $cuentaJuego["ultimo_codigo"] = $codigosLibres == 1;
                    $cuentaJuego["codigo"] = $codigoArrayOficial->codigo;
                    $cuentaJuego["juego"] = $correoJuego->juego;
                    $cuentaJuego["tipo_cuenta"] = $request->tipo_cuenta;
                    $cuentaJuego["consola"] = $request->consola;
                    $cuentaJuego["correo"] = $correoJuego->correo;
                    $cuentaJuego["contrasena"] = $correoJuego->contrasena;
                } else {
                    $notificacionExiste = Notificaciones::where('id_correo_juego', $correoJuego->id)
                    ->where('tipo', 'crear_codigos')
                    ->where('mensaje', 'Se necesita crear codigos')
                    ->first();

                    if ($notificacionExiste == null) {
                        Notificaciones::create([
                            'id_correo_juego' => $correoJuego->id,
                            'tipo' => 'crear_codigos',
                            'mensaje' => 'Se necesita crear codigos',
                        ]);
                    }

                    $cuentaJuego["mensaje"] = "Este correo: $correoJuego->correo Ya gasto todos los codigos por favor crear nuevos para poder generar la venta";
                    $cuentaJuego["resultado"] = false;
                }
            }
        } else {
            $notificacionExiste = Notificaciones::where('juego', $request->juego)
            ->where('tipo', 'crear_juego')
            ->where('mensaje', "Se necesita crear el juego en cuenta $request->tipo_cuenta para $request->consola")
            ->first();

            if ($notificacionExiste == null) {
                Notificaciones::create([
                    'juego' => $request->juego,
                    'tipo' => 'crear_juego',
                    'mensaje' => "Se necesita crear el juego en cuenta $request->tipo_cuenta para $request->consola",
                ]);
            }

            $cuentaJuego["mensaje"] = "Este juego en cuenta $request->tipo_cuenta para $request->consola no está disponible en nuestro inventario actualmente.";
            $cuentaJuego["resultado"] = false;
        }

        if ($cuentaJuego["resultado"]) {
            $existePeriodo = ResumenMensual::where('periodo', $hoy->format('m/Y'))->get()->first();

            // Ventas del mes
            $ventasMes = Ventas::whereMonth('created_at', $mesActual)
                ->whereYear('created_at', $anioActual);

            // Suma de ingresos por movimientos tipo Ingreso del mes
            $movimientoIngresoMes = Movimientos::whereMonth('created_at', operator: $mesActual)
                ->whereYear('created_at', $anioActual)
                ->where('tipo', 'Ingreso')
                ->sum('valor');

            // Cantidad de ventas y suma total del campo precio
            $cantidadVentasMes = $ventasMes->count();
            $sumaIngresosMes = $ventasMes->sum('precio');

            // Suma de egresos del mes
            $sumaEgresosMes = Movimientos::whereMonth('created_at', $mesActual)
                ->whereYear('created_at', $anioActual)
                ->where('tipo', 'Egreso')
                ->sum('valor');

            $ingresosTotalesMes = $sumaIngresosMes + $movimientoIngresoMes;
            $margenGananciaMes = $ingresosTotalesMes == 0 ? 0 : round((($sumaIngresosMes + $movimientoIngresoMes) - $sumaEgresosMes) / $ingresosTotalesMes, 2) * 100;
            $roiMes = $sumaEgresosMes == 0 ? 0 : round((($sumaIngresosMes + $movimientoIngresoMes) - $sumaEgresosMes) / $sumaEgresosMes, 2) * 100;

            if ($existePeriodo) {
                $existePeriodo->update([
                    'cantidad' => $cantidadVentasMes,
                    'ingresos' => intval($ingresosTotalesMes),
                    'egresos' => intval($sumaEgresosMes),
                    'utilidad_neta' => intval(($sumaIngresosMes + $movimientoIngresoMes) - $sumaEgresosMes),
                    'margen_ganancia' => round($margenGananciaMes, 2),
                    'roi' => round($roiMes, 2)
                ]);
            } else {
                ResumenMensual::create([
                    'periodo' => $hoy->format('m/Y'),
                    'cantidad' => $cantidadVentasMes,
                    'ingresos' => intval($ingresosTotalesMes),
                    'egresos' => intval($sumaEgresosMes),
                    'utilidad_neta' => intval(($sumaIngresosMes + $movimientoIngresoMes) - $sumaEgresosMes),
                    'margen_ganancia' => round($margenGananciaMes, 2),
                    'roi' => round($roiMes, 2)
                ]);
            }
        }

        return redirect()->route('ventas.create', ['cuenta_juego' => $cuentaJuego]);
    }

    public function edit(string $id)
    {
        $venta = Ventas::findOrFail($id);
        return Inertia::render('Ventas/Edit', ["venta" => $venta]);
    }

    public function update(Request $request, string $id)
    {
        $venta = Ventas::findOrFail($id);

        $validatedData = $request->validate([
            'cliente' => 'required',
            'medio_pago' => 'required',
            'precio' => 'required',
            'consola' => 'required',
        ]);

        $venta->update([
            'cliente' => $validatedData['cliente'],
            'medio_pago' => $validatedData['medio_pago'],
            'precio' => $validatedData['precio'],
            'consola' => $validatedData['consola'],
        ]);

        $mensajeEdit = "Se edito correctamente la venta";
        return redirect()->route('ventas.index', ['mensaje_edit' => $mensajeEdit]);
    }

    public function comprobarExistenciaJuego($tipo_cuenta, $consola, $juego)
    {
        $correoJuego = null;
        
        if ($tipo_cuenta == 'Primaria') {
            $licencia = $consola == 'PS4' ? "primaria_ps4" : "primaria_ps5";
            
            $correoJuego = CorreoJuego::where('juego', $juego)
                ->where($licencia, '<', 2)
                ->where('disponible', 1)
                ->orderBy('id')
                ->first();
        } elseif ($tipo_cuenta == 'Secundaria') {
            $licencia = "secundaria";
            
            $correoJuego = CorreoJuego::where('juego', $juego)
                ->where($consola == 'PS4' ? 'primaria_ps4' : 'primaria_ps5', '=', 2)
                ->where('secundaria', '=', 0)
                ->where('disponible', 1)
                ->orderBy('id')
                ->first();
        }
    
        return $correoJuego;
    }    

    public function buscarJuego(Request $request)
    {   
        $query = $request->input('juego');
        $juegos = CorreoJuego::where('juego', 'like', "%{$query}%")
        ->select('juego')
        ->distinct()
        ->limit(5)
        ->get();
    
        return response()->json($juegos);
    }

    public function destroy(string $id)
    {
        $venta = Ventas::findOrFail($id);
        $venta->delete();
        return redirect()->back();
    }
}
