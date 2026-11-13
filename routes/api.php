<?php

use App\Http\Controllers\Api\Manuales\ManualApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Login\LoginApiController;
use App\Http\Controllers\Api\Auth\AuthApiController;
use App\Http\Controllers\Api\Auth\DashboardApiController;
use App\Http\Controllers\Api\Config\RolesApiController;
use App\Http\Controllers\Api\Config\ConfiguracionApiController;
use App\Http\Controllers\Api\Config\NegocioApiController;
use App\Http\Controllers\Api\Config\AuditoriaApiController;
use App\Http\Controllers\Api\Productos\ProductosApiController;
use App\Http\Controllers\Api\Productos\InventarioApiController;
use App\Http\Controllers\Api\Productos\KardexApiController;
use App\Http\Controllers\Api\Caja\CajaApiController;
use App\Http\Controllers\Api\Caja\TurnoApiController;
use App\Http\Controllers\Api\Pos\PosApiController;
use App\Http\Controllers\Api\Pos\VentaApiController;
use App\Http\Controllers\Api\Pos\PedidoApiController;
use App\Http\Controllers\Api\Pos\ImpresionApiController;
use App\Http\Controllers\Api\Pos\SyncApiController;
use App\Http\Controllers\Api\Pos\MostradorApiController;
use App\Http\Controllers\Api\Clientes\ClienteApiController;
use App\Http\Controllers\Api\Clientes\PedidoEspecialApiController;
use App\Http\Controllers\Api\Compras\CompraApiController;
use App\Http\Controllers\Api\Compras\OrdenCompraApiController;
use App\Http\Controllers\Api\Compras\ProveedorApiController;
use App\Http\Controllers\Api\Produccion\ProduccionApiController;
use App\Http\Controllers\Api\Gastos\GastoApiController;
use App\Http\Controllers\Api\Reportes\ReporteApiController;
use App\Http\Controllers\Api\Mesas\MesaApiController;
use App\Http\Controllers\Api\Mesas\ConfigMesasApiController;
use App\Http\Controllers\Api\Notificaciones\NotificacionApiController;



Route::post('/login', [LoginApiController::class, 'login'])->middleware('throttle:login');
Route::post('/login-pin', [LoginApiController::class, 'loginPin'])->middleware('throttle:login-pin');

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/validate-reset-token', [LoginApiController::class, 'validateResetToken']);
    Route::post('/reset-password-confirm', [LoginApiController::class, 'resetPasswordConfirm']);
    Route::post('/admin/enviar/correo/password', [LoginApiController::class, 'enviarCorreoAdministrador']);
});

Route::middleware(['auth:sanctum', 'usuario.activo'])->group(function () {

    Route::post('/logout', [LoginApiController::class, 'logout']);

    Route::get('/me', [AuthApiController::class, 'me']);
    Route::put('/me/pin', [AuthApiController::class, 'cambiarPin'])->middleware('throttle:10,1');
    // Sin límite por minuto: solo cuentan los PIN incorrectos (25 seguidos → esperar un minuto)
    Route::post('/me/desbloquear', [AuthApiController::class, 'desbloquear']);
    Route::post('/me/cambiar-usuario', [AuthApiController::class, 'cambiarUsuario']);

    Route::get('/datos', [DashboardApiController::class, 'datos']);

    // ── NOTIFICACIONES (cada usuario las suyas) ───────────────
    Route::get('/notificaciones/resumen', [NotificacionApiController::class, 'resumen']);
    Route::get('/notificaciones', [NotificacionApiController::class, 'index']);
    Route::post('/notificaciones/leer-todas', [NotificacionApiController::class, 'leerTodas']);
    Route::patch('/notificaciones/{id}/leer', [NotificacionApiController::class, 'leer'])->whereNumber('id');
    Route::delete('/notificaciones/{id}', [NotificacionApiController::class, 'destroy'])->whereNumber('id');
    Route::delete('/notificaciones', [NotificacionApiController::class, 'vaciar']);


    // ── MANUALES DE USO ───────────────────────────────────────
    Route::get('/manuales', [ManualApiController::class, 'index'])->middleware('permission:manuales.ver');
    Route::middleware('permission:roles.gestionar')->group(function () {
        Route::get('/manuales/permisos', [ManualApiController::class, 'permisos']);
        Route::put('/manuales/permisos/{rol}', [ManualApiController::class, 'guardarPermisos'])->whereNumber('rol');
    });

    // ── CONFIGURACIÓN DEL NEGOCIO ─────────────────────────────
    // Lectura para cualquier usuario autenticado (ticket, moneda, IVA)
    Route::get('/configuracion/negocio', [NegocioApiController::class, 'mostrar']);
    Route::post('/configuracion/negocio', [NegocioApiController::class, 'actualizar'])->middleware('permission:configuracion.gestionar');

    // ── ROLES Y PERMISOS ──────────────────────────────────────
    Route::get('/admin/roles/tabla', [RolesApiController::class,'listadoRoles'])->middleware('permission:roles.gestionar|usuarios.gestionar');

    Route::middleware('permission:roles.gestionar')->group(function () {
        Route::post('/admin/roles/borrar-global', [RolesApiController::class, 'borrarRolGlobal']);
        Route::get('/admin/roles/permisos/tabla/{id}', [RolesApiController::class,'tablaRolesPermisos']);
        Route::post('/admin/roles/permiso/borrar', [RolesApiController::class, 'borrarPermiso']);
        Route::post('/admin/roles/nuevo-rol', [RolesApiController::class, 'nuevoRol']);
        Route::post('/admin/roles/permiso/agregar', [RolesApiController::class, 'agregarPermiso']);
        Route::get('/admin/roles/permisos-todos/tabla', [RolesApiController::class,'tablaTodosPermisos']);
        Route::get('/admin/roles/{id}/matriz', [RolesApiController::class, 'matrizPermisos'])->whereNumber('id');
        Route::put('/admin/roles/{id}/permisos', [RolesApiController::class, 'guardarMatriz'])->whereNumber('id');
        Route::post('/admin/permisos/extra-borrar', [RolesApiController::class, 'borrarPermisoGlobal']);
        Route::post('/admin/permisos/extra-nuevo', [RolesApiController::class, 'nuevoPermisoExtra']);
    });

    // ── AUDITORÍA ─────────────────────────────────────────────
    Route::middleware('permission:auditoria.ver')->group(function () {
        Route::get('/auditoria', [AuditoriaApiController::class, 'index']);
        Route::get('/auditoria/filtros', [AuditoriaApiController::class, 'filtros']);
    });

    // ── USUARIOS ──────────────────────────────────────────────
    Route::middleware('permission:usuarios.gestionar')->group(function () {
        Route::get('/admin/usuarios/tabla', [RolesApiController::class,'tablaUsuarios']);
        Route::post('/admin/permisos/nuevo-usuario', [RolesApiController::class, 'nuevoUsuario']);
        Route::post('/admin/informacion/administrador', [RolesApiController::class, 'informacionAdministrador']);
        Route::put('/admin/actualizar/administrador/{id}', [RolesApiController::class, 'actualizarAdministrador']);
        Route::delete('/admin/usuarios/{usuario}/pin', [AuthApiController::class, 'resetearPin']);
    });

    // ── CATEGORÍAS Y UNIDADES DE MEDIDA ───────────────────────
    // La lectura también la necesita el formulario de productos
    Route::middleware('permission:catalogos.gestionar|productos.ver|productos.gestionar')->group(function () {
        Route::get('/admin/categorias/tabla', [ConfiguracionApiController::class,'tablaCategorias']);
        Route::get('/admin/unidadmedida/tabla', [ConfiguracionApiController::class,'tablaUnidadMedida']);
    });

    Route::middleware('permission:catalogos.gestionar')->group(function () {
        Route::post('/admin/categorias/nuevo', [ConfiguracionApiController::class, 'registrarCategoria']);
        Route::put('/admin/categorias/actualizar/{id}', [ConfiguracionApiController::class, 'actualizarCategoria']);
        Route::delete('/admin/categorias/{id}', [ConfiguracionApiController::class, 'borrarCategoria'])->whereNumber('id');
        Route::post('/admin/unidadmedida/nuevo', [ConfiguracionApiController::class, 'registrarUnidadMedida']);
        Route::put('/admin/unidadmedida/actualizar/{id}', [ConfiguracionApiController::class, 'actualizarUnidadMedida']);
        Route::delete('/admin/unidadmedida/{id}', [ConfiguracionApiController::class, 'borrarUnidadMedida'])->whereNumber('id');
    });

    // ── IMPRESORAS ────────────────────────────────────────────
    Route::middleware('permission:impresoras.gestionar')->group(function () {
        Route::get('/admin/impresora/index',                    [ConfiguracionApiController::class, 'indexImpresora']);
        Route::post('/admin/impresora/store',                   [ConfiguracionApiController::class, 'nuevaImpresora']);
        Route::get('/admin/impresora/{impresora}',              [ConfiguracionApiController::class, 'showImpresora']);
        Route::post('/admin/impresora/{impresora}/update',      [ConfiguracionApiController::class, 'updateImpresora']);
        Route::delete('/admin/impresora/{impresora}/destroy',   [ConfiguracionApiController::class, 'borrarImpresora']);
        Route::post('/admin/impresora/{impresora}/test',        [ConfiguracionApiController::class, 'testPrintImpresoraReal']);
        Route::post('/admin/impresora/{impresora}/test-produccion', [ConfiguracionApiController::class, 'testProduccion']);
    });

    // ── PROVEEDORES, COMPRAS Y CUENTAS POR PAGAR ──────────────
    // La lista también se usa para asociar un gasto a un proveedor
    Route::get('/compras/proveedores', [ProveedorApiController::class, 'index'])
        ->middleware('permission:proveedores.gestionar|compras.ver|compras.gestionar|cuentas_pagar.gestionar|gastos.registrar|gastos.ver');
    Route::middleware('permission:proveedores.gestionar|compras.ver|compras.gestionar|cuentas_pagar.gestionar')->group(function () {
        Route::get('/compras/proveedores/{proveedor}', [ProveedorApiController::class, 'show']);
    });
    Route::middleware('permission:proveedores.gestionar')->group(function () {
        Route::post('/compras/proveedores', [ProveedorApiController::class, 'store']);
        Route::put('/compras/proveedores/{proveedor}', [ProveedorApiController::class, 'update']);
    });

    Route::middleware('permission:compras.ver|compras.gestionar')->group(function () {
        Route::get('/compras/ordenes', [OrdenCompraApiController::class, 'index']);
        Route::get('/compras/ordenes/{orden}', [OrdenCompraApiController::class, 'show']);
        Route::get('/compras/ordenes/{orden}/pdf', [OrdenCompraApiController::class, 'pdf']);
        Route::get('/compras/catalogo', [CompraApiController::class, 'catalogo']);
        Route::get('/compras', [CompraApiController::class, 'index']);
        Route::get('/compras/historial-producto/{producto}', [CompraApiController::class, 'historialProducto']);
        Route::get('/compras/{compra}', [CompraApiController::class, 'show'])->whereNumber('compra');
    });
    Route::middleware('permission:compras.gestionar')->group(function () {
        Route::post('/compras/ordenes', [OrdenCompraApiController::class, 'store']);
        Route::put('/compras/ordenes/{orden}', [OrdenCompraApiController::class, 'update']);
        Route::post('/compras/ordenes/{orden}/{accion}', [OrdenCompraApiController::class, 'accion'])->whereIn('accion', ['enviar', 'cancelar', 'cerrar']);
        Route::post('/compras', [CompraApiController::class, 'store']);
        Route::post('/compras/{compra}/anular', [CompraApiController::class, 'anular']);
    });

    Route::middleware('permission:cuentas_pagar.gestionar')->group(function () {
        Route::get('/cuentas-pagar', [CompraApiController::class, 'cuentas']);
        Route::get('/cuentas-pagar/pagos', [CompraApiController::class, 'pagos']);
        Route::post('/cuentas-pagar/proveedores/{proveedor}/pagos', [CompraApiController::class, 'registrarPago']);
        Route::post('/cuentas-pagar/pagos/{pago}/anular', [CompraApiController::class, 'anularPago']);
        Route::post('/cuentas-pagar/{cuenta}/notas-credito', [CompraApiController::class, 'notaCredito']);
        Route::post('/cuentas-pagar/notas-credito/{nota}/anular', [CompraApiController::class, 'anularNotaCredito']);
    });

    // ── PRODUCTOS ─────────────────────────────────────────────
    Route::middleware('permission:productos.ver|productos.gestionar')->group(function () {
        Route::get('/productos/tabla', [ProductosApiController::class, 'tablaProductos']);
        Route::get('/productos/editar/{id}', [ProductosApiController::class, 'mostrarProducto']);
        Route::get('/productos/orden-pos', [ProductosApiController::class, 'ordenPos']);
    });

    Route::middleware('permission:productos.gestionar')->group(function () {
        Route::post('/productos/registro', [ProductosApiController::class, 'registrarProducto']);
        Route::post('/productos/actualizar/{id}', [ProductosApiController::class, 'actualizarProducto']);
        Route::put('/productos/orden-pos', [ProductosApiController::class, 'ordenarPos']);
        Route::delete('/productos/{id}', [ProductosApiController::class, 'borrarProducto'])->whereNumber('id');
    });

    // ── INVENTARIO ────────────────────────────────────────────
    Route::get('/inventario/stock', [InventarioApiController::class, 'stock'])->middleware('permission:inventario.ver|inventario.registrar');

    Route::middleware('permission:inventario.ver')->group(function () {
        Route::get('/inventario/entradas',      [InventarioApiController::class, 'listarEntradas']);
        Route::get('/inventario/entradas/{id}', [InventarioApiController::class, 'mostrarEntrada']);
    });

    // Los ajustes además exigen inventario.ajustar (se valida en el controlador)
    Route::post('/inventario/entradas/registrar', [InventarioApiController::class, 'registrarEntrada'])->middleware('permission:inventario.registrar|inventario.ajustar');

    Route::put('/inventario/stock-minimo/{producto}', [InventarioApiController::class, 'actualizarStockMinimo'])->middleware('permission:inventario.ajustar|productos.gestionar');
    Route::get('/inventario/alertas', [InventarioApiController::class, 'alertas'])->middleware('permission:inventario.ver|productos.gestionar');
    Route::get('/inventario/movimientos', [KardexApiController::class, 'movimientos'])->middleware('permission:inventario.ver');
    Route::get('/inventario/kardex/{producto}', [KardexApiController::class, 'kardex'])->middleware('permission:inventario.kardex');

    // ── CAJA Y TURNOS ─────────────────────────────────────────
    // Catálogos (cajas, métodos de pago, denominaciones): los usan caja y POS
    Route::get('/caja/catalogos', [CajaApiController::class, 'catalogos']);

    Route::get('/caja/turno-actual', [TurnoApiController::class, 'actual'])
        ->middleware('permission:caja.abrir|caja.movimientos|caja.arqueo|caja.cerrar|pos.vender');
    Route::post('/caja/turnos/abrir', [TurnoApiController::class, 'abrir'])->middleware('permission:caja.abrir');
    Route::post('/caja/turnos/{turno}/movimientos', [TurnoApiController::class, 'movimiento'])->middleware('permission:caja.movimientos');
    Route::post('/caja/movimientos/{movimiento}/anular', [TurnoApiController::class, 'anularMovimiento'])->middleware('permission:caja.movimientos');
    Route::post('/caja/turnos/{turno}/arqueos', [TurnoApiController::class, 'arqueo'])->middleware('permission:caja.arqueo');
    Route::post('/caja/turnos/{turno}/cerrar', [TurnoApiController::class, 'cerrar'])->middleware('permission:caja.cerrar');
    Route::post('/caja/turnos/{turno}/reabrir', [TurnoApiController::class, 'reabrir'])->middleware('permission:caja.reabrir');
    Route::get('/caja/turnos', [TurnoApiController::class, 'historial'])->middleware('permission:caja.historial');
    // El detalle valida en el controlador: historial, o el turno es del usuario
    Route::get('/caja/turnos/{turno}', [TurnoApiController::class, 'mostrar'])
        ->middleware('permission:caja.historial|caja.abrir|caja.cerrar');

    // ── PUNTO DE VENTA ────────────────────────────────────────
    Route::middleware('permission:pos.vender')->group(function () {
        Route::get('/pos/catalogo', [PosApiController::class, 'catalogo']);
        Route::get('/pos/ping', [SyncApiController::class, 'ping']);
        Route::post('/pos/sync', [SyncApiController::class, 'ventas']);
        Route::post('/pos/ventas', [VentaApiController::class, 'store']);
        // Anular y devolver: el permiso se valida en el servicio
        Route::post('/pos/ventas/{venta}/anular', [VentaApiController::class, 'anular']);
        Route::post('/pos/ventas/{venta}/devoluciones', [VentaApiController::class, 'devolver']);
    });

    Route::middleware('permission:pos.historial|pos.vender')->group(function () {
        Route::get('/pos/ventas', [VentaApiController::class, 'index']);
        Route::get('/pos/ventas/{venta}', [VentaApiController::class, 'show']);
    });

    // ── IMPRESIÓN ─────────────────────────────────────────────
    Route::post('/impresion/ventas/{venta}', [ImpresionApiController::class, 'venta'])->middleware('permission:pos.vender|pos.historial');
    Route::get('/impresion/offline', [ImpresionApiController::class, 'offline'])->middleware('permission:pos.vender');
    Route::post('/impresion/pedidos/{pedido}/comandas', [ImpresionApiController::class, 'comandasPedido'])->middleware('permission:pos.precuentas');
    Route::post('/impresion/turnos/{turno}', [ImpresionApiController::class, 'turno'])->middleware('permission:caja.cerrar|caja.historial|caja.arqueo');
    // caja.abrir_cajon se revisa en el controlador: sin él se pide el PIN de quien lo tenga
    Route::post('/impresion/cajon', [ImpresionApiController::class, 'cajon'])->middleware('permission:caja.movimientos|pos.vender');
    // La tablet que imprime (servidor en la nube): toma la cola y reporta el resultado
    Route::post('/impresion/cola/tomar', [ImpresionApiController::class, 'tomarCola'])->middleware('permission:pos.vender|pos.precuentas');
    Route::post('/impresion/trabajos/{trabajo}/resultado', [ImpresionApiController::class, 'resultadoDispositivo'])->middleware('permission:pos.vender|pos.precuentas');
    Route::get('/impresion/trabajos', [ImpresionApiController::class, 'trabajos'])->middleware('permission:impresoras.gestionar');
    Route::delete('/impresion/trabajos', [ImpresionApiController::class, 'eliminarErrores'])->middleware('permission:impresoras.gestionar');
    Route::delete('/impresion/trabajos/{trabajo}', [ImpresionApiController::class, 'eliminar'])->middleware('permission:impresoras.gestionar');
    Route::post('/impresion/trabajos/{trabajo}/reintentar', [ImpresionApiController::class, 'reintentar'])
        ->middleware('permission:impresoras.gestionar|pos.vender');

    Route::middleware('permission:pos.precuentas')->group(function () {
        Route::get('/pos/pedidos', [PedidoApiController::class, 'index']);
        Route::post('/pos/pedidos', [PedidoApiController::class, 'store']);
        Route::put('/pos/pedidos/{pedido}', [PedidoApiController::class, 'update']);
        Route::post('/pos/pedidos/{pedido}/cancelar', [PedidoApiController::class, 'cancelar']);
    });

    // ── CLIENTES Y CUENTAS POR COBRAR ─────────────────────────
    // El POS busca clientes para asignarlos a la venta
    Route::middleware('permission:clientes.ver|pos.vender')->group(function () {
        Route::get('/clientes', [ClienteApiController::class, 'index']);
        Route::get('/clientes/{cliente}', [ClienteApiController::class, 'show']);
    });
    Route::post('/clientes', [ClienteApiController::class, 'store'])->middleware('permission:clientes.gestionar');
    Route::put('/clientes/{cliente}', [ClienteApiController::class, 'update'])->middleware('permission:clientes.gestionar');

    Route::middleware('permission:cuentas_cobrar.gestionar')->group(function () {
        Route::get('/cuentas-cobrar', [ClienteApiController::class, 'cuentas']);
        Route::get('/clientes/{cliente}/estado-cuenta', [ClienteApiController::class, 'estadoCuenta']);
        Route::get('/clientes/{cliente}/estado-cuenta/pdf', [ClienteApiController::class, 'estadoCuentaPdf']);
        Route::post('/clientes/{cliente}/abonos', [ClienteApiController::class, 'registrarAbono']);
        // Anular: el permiso se valida en el servicio
        Route::post('/clientes/abonos/{abono}/anular', [ClienteApiController::class, 'anularAbono']);
    });

    // ── PEDIDOS ESPECIALES (ENCARGOS) ─────────────────────────
    Route::middleware('permission:pedidos.gestionar')->group(function () {
        Route::get('/pedidos-especiales', [PedidoEspecialApiController::class, 'index']);
        Route::post('/pedidos-especiales', [PedidoEspecialApiController::class, 'store']);
        Route::get('/pedidos-especiales/{pedido}', [PedidoEspecialApiController::class, 'show']);
        Route::put('/pedidos-especiales/{pedido}', [PedidoEspecialApiController::class, 'update']);
        Route::post('/pedidos-especiales/{pedido}/anticipos', [PedidoEspecialApiController::class, 'anticipo']);
        Route::post('/pedidos-especiales/{pedido}/cancelar', [PedidoEspecialApiController::class, 'cancelar']);
    });

    // ── PRODUCCIÓN, RECETAS Y MERMAS ──────────────────────────
    Route::get('/produccion/catalogo', [ProduccionApiController::class, 'catalogo'])
        ->middleware('permission:recetas.gestionar|produccion.ver|produccion.registrar|mermas.registrar');

    Route::middleware('permission:recetas.gestionar|produccion.ver|produccion.registrar')->group(function () {
        Route::get('/produccion/recetas', [ProduccionApiController::class, 'recetas']);
        Route::get('/produccion/recetas/{receta}', [ProduccionApiController::class, 'receta']);
    });
    Route::middleware('permission:recetas.gestionar')->group(function () {
        Route::post('/produccion/recetas', [ProduccionApiController::class, 'crearReceta']);
        Route::put('/produccion/recetas/{receta}', [ProduccionApiController::class, 'actualizarReceta']);
        Route::post('/produccion/recetas/{receta}/activar', [ProduccionApiController::class, 'activarReceta']);
    });

    Route::middleware('permission:produccion.ver|produccion.registrar')->group(function () {
        Route::get('/produccion', [ProduccionApiController::class, 'index']);
        Route::get('/produccion/resumen', [ProduccionApiController::class, 'resumen']);
        Route::get('/produccion/{produccion}', [ProduccionApiController::class, 'show'])->whereNumber('produccion');
    });
    Route::middleware('permission:produccion.registrar')->group(function () {
        Route::post('/produccion', [ProduccionApiController::class, 'store']);
        Route::post('/produccion/{produccion}/anular', [ProduccionApiController::class, 'anular']);
    });

    Route::get('/mermas', [ProduccionApiController::class, 'mermas'])->middleware('permission:mermas.registrar|produccion.ver');
    Route::middleware('permission:mermas.registrar')->group(function () {
        Route::post('/mermas', [ProduccionApiController::class, 'registrarMermas']);
        Route::post('/mermas/{merma}/anular', [ProduccionApiController::class, 'anularMerma']);
    });

    // ── GASTOS ────────────────────────────────────────────────
    Route::get('/gastos/categorias', [GastoApiController::class, 'categorias'])->middleware('permission:gastos.ver|gastos.registrar|gastos.categorias');
    Route::middleware('permission:gastos.ver|gastos.registrar')->group(function () {
        Route::get('/gastos', [GastoApiController::class, 'index']);
        Route::get('/gastos/{gasto}/comprobante', [GastoApiController::class, 'comprobante']);
    });
    Route::middleware('permission:gastos.registrar')->group(function () {
        Route::post('/gastos', [GastoApiController::class, 'store']);
        // Anular: el permiso se valida en el servicio
        Route::post('/gastos/{gasto}/anular', [GastoApiController::class, 'anular']);
    });
    Route::middleware('permission:gastos.ver')->group(function () {
        Route::get('/gastos/resumen', [GastoApiController::class, 'resumen']);
    });
    Route::middleware('permission:gastos.categorias')->group(function () {
        Route::post('/gastos/categorias', [GastoApiController::class, 'guardarCategoria']);
        Route::put('/gastos/categorias/{id}', [GastoApiController::class, 'actualizarCategoria'])->whereNumber('id');
    });

    // ── MESAS, CUENTAS SEPARADAS Y COMANDAS ───────────────────
    Route::middleware('permission:pos.vender')->group(function () {
        Route::get('/mesas/mapa', [MesaApiController::class, 'mapa']);
        // Venta rápida: pedidos con la comanda enviada que se cobran después
        Route::get('/mostrador/pedidos', [MostradorApiController::class, 'index']);
        Route::post('/mostrador/pedidos', [MostradorApiController::class, 'store']);
        Route::post('/mostrador/pedidos/{ticket}/items', [MostradorApiController::class, 'agregar']);
        Route::get('/mesas/catalogo', [MesaApiController::class, 'catalogo']);
        Route::get('/mesas/terminales/{terminal}', [MesaApiController::class, 'terminal']);
        Route::post('/mesas/terminales/{terminal}/abrir', [MesaApiController::class, 'abrir']);
        Route::put('/mesas/ordenes/{orden}', [MesaApiController::class, 'actualizar']);
        Route::post('/mesas/ordenes/{orden}/items', [MesaApiController::class, 'agregar']);
        Route::post('/mesas/ordenes/{orden}/enviar', [MesaApiController::class, 'enviar']);
        Route::post('/mesas/ordenes/{orden}/entregar', [MesaApiController::class, 'entregar']);
        Route::post('/mesas/ordenes/{orden}/tickets', [MesaApiController::class, 'crearTicket']);
        Route::post('/mesas/ordenes/{orden}/mover', [MesaApiController::class, 'mover']);
        Route::post('/mesas/ordenes/{orden}/precuenta', [MesaApiController::class, 'precuenta']);
        Route::post('/mesas/ordenes/{orden}/cobrar', [MesaApiController::class, 'cobrar']);
        // Cerrar con productos sin cobrar pide además autorización (en el servicio)
        Route::post('/mesas/ordenes/{orden}/cerrar', [MesaApiController::class, 'cerrar']);
        Route::post('/mesas/ordenes/{orden}/trasladar', [MesaApiController::class, 'trasladar']);
        Route::put('/mesas/items/{item}', [MesaApiController::class, 'actualizarItem']);
        Route::post('/mesas/items/{item}/quitar', [MesaApiController::class, 'quitarItem']);
        Route::post('/mesas/items/{item}/dividir', [MesaApiController::class, 'dividir']);
        Route::put('/mesas/tickets/{ticket}', [MesaApiController::class, 'renombrarTicket']);
        Route::delete('/mesas/tickets/{ticket}', [MesaApiController::class, 'eliminarTicket']);
        // Cocina y barra trabajan con su ticket impreso: desde la mesa se reimprime o se marca entregada
        Route::post('/mesas/comandas/{comanda}/entregar', [MesaApiController::class, 'entregarComanda']);
        Route::post('/mesas/comandas/{comanda}/imprimir', [MesaApiController::class, 'imprimirComanda']);
        Route::post('/mesas/comandas/{comanda}/anular', [MesaApiController::class, 'anularComanda']);
    });

    Route::middleware('permission:mesas.configurar')->group(function () {
        Route::get('/mesas/config/terminales', [ConfigMesasApiController::class, 'terminales']);
        Route::post('/mesas/config/terminales', [ConfigMesasApiController::class, 'guardarTerminal']);
        Route::put('/mesas/config/terminales/orden', [ConfigMesasApiController::class, 'ordenarTerminales']);
        Route::put('/mesas/config/terminales/{id}', [ConfigMesasApiController::class, 'actualizarTerminal'])->whereNumber('id');
        Route::delete('/mesas/config/terminales/{id}', [ConfigMesasApiController::class, 'borrarTerminal'])->whereNumber('id');
        Route::get('/mesas/config/areas', [ConfigMesasApiController::class, 'areas']);
        Route::post('/mesas/config/areas', [ConfigMesasApiController::class, 'guardarArea']);
        Route::put('/mesas/config/areas/{id}', [ConfigMesasApiController::class, 'actualizarArea'])->whereNumber('id');
        Route::delete('/mesas/config/areas/{id}', [ConfigMesasApiController::class, 'borrarArea'])->whereNumber('id');
        Route::put('/mesas/config/categorias', [ConfigMesasApiController::class, 'asignarCategorias']);
        Route::get('/mesas/config/modificadores', [ConfigMesasApiController::class, 'grupos']);
        Route::post('/mesas/config/modificadores', [ConfigMesasApiController::class, 'guardarGrupo']);
        Route::put('/mesas/config/modificadores/{id}', [ConfigMesasApiController::class, 'actualizarGrupo'])->whereNumber('id');
        Route::get('/mesas/config/productos/{id}/modificadores', [ConfigMesasApiController::class, 'modificadoresProducto'])->whereNumber('id');
        Route::put('/mesas/config/productos/{id}/modificadores', [ConfigMesasApiController::class, 'guardarModificadoresProducto'])->whereNumber('id');
        Route::delete('/mesas/config/productos/{id}/modificadores', [ConfigMesasApiController::class, 'quitarModificadoresProducto'])->whereNumber('id');
    });

    // ── REPORTES Y PANEL ──────────────────────────────────────
    Route::get('/panel', [ReporteApiController::class, 'panel'])->middleware('permission:dashboard.ver|reportes.ver');
    Route::middleware('permission:reportes.ver')->group(function () {
        Route::get('/reportes', [ReporteApiController::class, 'catalogo']);
        Route::get('/reportes/{tipo}', [ReporteApiController::class, 'show']);
    });
    Route::middleware(['permission:reportes.exportar', 'throttle:30,1'])->group(function () {
        Route::get('/reportes/{tipo}/excel', [ReporteApiController::class, 'excel']);
        Route::get('/reportes/{tipo}/pdf', [ReporteApiController::class, 'pdf']);
    });

    Route::middleware('permission:configuracion.gestionar')->group(function () {
        Route::get('/caja/cajas', [CajaApiController::class, 'indexCajas']);
        Route::post('/caja/cajas', [CajaApiController::class, 'storeCaja']);
        Route::put('/caja/cajas/{caja}', [CajaApiController::class, 'updateCaja']);
        Route::get('/caja/metodos-pago', [CajaApiController::class, 'indexMetodos']);
        Route::post('/caja/metodos-pago', [CajaApiController::class, 'storeMetodo']);
        Route::put('/caja/metodos-pago/{metodo}', [CajaApiController::class, 'updateMetodo']);
    });

});
