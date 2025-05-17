<?php

namespace App\Http\Controllers;

use App\Models\CierreCaja;
use App\Models\Movimientos;
use App\Models\Ventas;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class CierreCajaController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $mensaje = $request->get('mensaje', '');
    
        $cierreCajas = CierreCaja::when($search, function ($query, $search) {
            return $query->where('fecha', $search);
        })
        ->orderBy('fecha', 'desc')
        ->limit(10)
        ->get();
    
    
        return Inertia::render('CierreCaja/Index', [
            'cierre_cajas' => $cierreCajas,
            'search' => $search,
            'mensaje' => $mensaje,
        ]);
    }

    public function create(Request $request)
    {
        $fecha = $request->input('fecha', Carbon::today());
        $ventas = Ventas::whereDate('created_at', $fecha)
        ->with('correoJuego')
        ->get()
        ->map(function ($venta) {
            return [
                'cliente' => $venta->cliente,
                'tipo_cuenta' => $venta->tipo_cuenta,
                'consola' => $venta->consola,
                'precio' => $venta->precio,
                'medio_pago' => $venta->medio_pago,
                'correoJuego' => [
                    'correo' => $venta->correoJuego->correo ?? null,
                    'juego' => $venta->correoJuego->juego ?? null,
                ],
            ];
        });

        $ultimoCierre = CierreCaja::latest()->first();
        $saldoInicial = $ultimoCierre->saldo_final ?? 0;

        $ingresos = Ventas::whereDate('created_at', $fecha)
        ->sum('precio');

        $movimientoIngresoHoy = Movimientos::whereDate('created_at', Carbon::today())
        ->where('tipo', 'Ingreso')
        ->sum('valor');

        $egresos = Movimientos::where('tipo', 'Egreso')
        ->whereDate('created_at', $fecha)
        ->sum('valor');

        $saldoFinal = $saldoInicial + ($ingresos - $egresos);
    
        return Inertia::render('CierreCaja/Create', [
            'ventas' => $ventas,
            'ingresos' => intval($ingresos + $movimientoIngresoHoy),
            'egresos' => intval($egresos),
            'saldo_inicial' => intval($saldoInicial),
            'saldo_final' => intval($saldoFinal),
            'fecha' => $fecha,
        ]);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'fecha' => 'required|unique:cierre_cajas,fecha',
            'saldo_inicial' => 'required',
            'ingresos' => 'required',
            'egresos' => 'required',
            'saldo_final' => 'required',
        ]);

        CierreCaja::create([
            'fecha' => $validatedData['fecha'],
            'saldo_inicial' => $validatedData['saldo_inicial'],
            'ingresos' => $validatedData['ingresos'],
            'egresos' => $validatedData['egresos'],
            'saldo_final' => $validatedData['saldo_final'],
            'observaciones' => $request->observaciones,
        ]);

        return redirect()->route("cierre-caja.index", ['mensaje' => 'Se cerro la caja correctamente']);
    }

    public function destroy(string $id)
    {
        $cierreCaja = CierreCaja::findOrFail($id);
        $cierreCaja->delete();

        return redirect()->back();
    }
}
