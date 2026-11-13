<?php

/*
|--------------------------------------------------------------------------
| Catálogo de permisos del sistema (guard "api")
|--------------------------------------------------------------------------
|
| Formato: 'modulo.accion' => 'Descripción'. El seeder RolesAndPermissionsSeeder
| los crea/actualiza y los asigna a los roles base. El rol "admin" pasa todas
| las verificaciones (Gate::before en AppServiceProvider).
|
*/

return [

    'permisos' => [
        // Dashboard
        'dashboard.ver'             => 'Ver el panel de inicio',

        // Punto de venta
        'pos.vender'                => 'Registrar ventas en el POS',
        'pos.descuento'             => 'Aplicar descuentos dentro del máximo permitido',
        'pos.descuento.autorizar'   => 'Autorizar descuentos mayores al máximo',
        'pos.anular'                => 'Anular ventas',
        'pos.devolver'              => 'Registrar devoluciones',
        'pos.historial'             => 'Ver historial de ventas',
        'pos.precuentas'            => 'Gestionar precuentas y pedidos pendientes',
        'pos.ver_existencias'       => 'Ver la existencia en las tarjetas de productos (venta rápida y mesas)',

        // Mesas
        'mesas.configurar'          => 'Configurar mesas, áreas de preparación y modificadores',

        // Caja
        'caja.abrir'                => 'Abrir turno de caja',
        'caja.cerrar'               => 'Cerrar turno de caja',
        'caja.movimientos'          => 'Registrar ingresos y egresos de caja',
        'caja.abrir_cajon'          => 'Abrir el cajón de dinero sin una venta',
        'caja.arqueo'               => 'Realizar arqueos',
        'caja.historial'            => 'Ver historial de cierres',
        'caja.reabrir'              => 'Reabrir turnos cerrados',
        'caja.turno_compartido'     => 'Vender y cobrar en el turno que abrió otra persona',

        // Productos e inventario
        'productos.ver'             => 'Ver catálogo de productos',
        'productos.gestionar'       => 'Crear y editar productos',
        'productos.precios'         => 'Cambiar precios y costos',
        'catalogos.gestionar'       => 'Gestionar categorías y unidades de medida',
        'inventario.ver'            => 'Ver existencias e historial de inventario',
        'inventario.registrar'      => 'Registrar entradas y salidas de inventario',
        'inventario.ajustar'        => 'Realizar ajustes de inventario',
        'inventario.autorizar_baja' => 'Autorizar salidas y ajustes a la baja de insumos de receta',
        'inventario.kardex'         => 'Ver kardex',

        // Compras y proveedores
        'proveedores.gestionar'     => 'Gestionar proveedores',
        'compras.ver'               => 'Ver compras y órdenes de compra',
        'compras.gestionar'         => 'Registrar órdenes de compra y recepciones',
        'cuentas_pagar.gestionar'   => 'Registrar pagos a proveedores',

        // Producción
        'recetas.gestionar'         => 'Gestionar recetas',
        'produccion.ver'            => 'Ver producción',
        'produccion.registrar'      => 'Registrar producción diaria',
        'produccion.ver_receta'     => 'Ver los ingredientes de las recetas',
        'mermas.registrar'          => 'Registrar mermas',

        // Clientes
        'clientes.ver'              => 'Ver clientes',
        'clientes.gestionar'        => 'Crear y editar clientes',
        'cuentas_cobrar.gestionar'  => 'Registrar abonos y ver estados de cuenta',
        'pedidos.gestionar'         => 'Gestionar pedidos especiales',
        'clientes.credito_exceder'  => 'Autorizar ventas a crédito sobre el límite del cliente',

        // Gastos
        'gastos.ver'                => 'Ver gastos',
        'gastos.registrar'          => 'Registrar gastos',
        'gastos.categorias'         => 'Crear y editar categorías de gasto',

        // Impresoras
        'impresoras.gestionar'      => 'Configurar impresoras',

        // Reportes
        'reportes.ver'              => 'Ver reportes',
        'reportes.exportar'         => 'Exportar reportes a Excel y PDF',

        // Administración
        'usuarios.gestionar'        => 'Gestionar usuarios',
        'roles.gestionar'           => 'Gestionar roles y permisos',
        'auditoria.ver'             => 'Ver registro de auditoría',
        'configuracion.gestionar'   => 'Editar la configuración del negocio',

        // Ayuda
        'manuales.ver'              => 'Ver los manuales de uso del sistema',
    ],

    // Módulo de cada permiso (lo que va antes del punto) para la matriz de roles
    'modulos' => [
        'dashboard'      => 'Panel de inicio',
        'pos'            => 'Punto de venta',
        'mesas'          => 'Mesas',
        'caja'           => 'Caja',
        'productos'      => 'Productos',
        'catalogos'      => 'Catálogos',
        'inventario'     => 'Inventario',
        'proveedores'    => 'Proveedores',
        'compras'        => 'Compras',
        'cuentas_pagar'  => 'Cuentas por pagar',
        'recetas'        => 'Recetas',
        'produccion'     => 'Producción',
        'mermas'         => 'Mermas',
        'clientes'       => 'Clientes',
        'cuentas_cobrar' => 'Cuentas por cobrar',
        'pedidos'        => 'Pedidos especiales',
        'gastos'         => 'Gastos',
        'impresoras'     => 'Impresoras',
        'reportes'       => 'Reportes',
        'usuarios'       => 'Usuarios',
        'roles'          => 'Roles y permisos',
        'auditoria'      => 'Auditoría',
        'configuracion'  => 'Configuración',
        'manuales'       => 'Manuales',
    ],

    // Permisos por rol base (el rol "admin" no necesita lista: tiene acceso total)
    'roles' => [
        // Abre su turno y atiende todo el día: ventas, mesas, caja, compras, gastos,
        // inventario (entradas y salidas), producción y mermas. Lo que no hace:
        // anular, devolver, ajustar inventario, reabrir turnos, reportes y configuración
        'mesero' => [
            'dashboard.ver',
            'pos.vender', 'pos.descuento', 'pos.historial', 'pos.precuentas', 'pos.ver_existencias',
            'caja.abrir', 'caja.cerrar', 'caja.movimientos', 'caja.arqueo', 'caja.turno_compartido',
            'productos.ver', 'inventario.ver', 'inventario.registrar', 'inventario.kardex',
            'proveedores.gestionar', 'compras.ver', 'compras.gestionar', 'cuentas_pagar.gestionar',
            'produccion.ver', 'produccion.registrar', 'mermas.registrar',
            'clientes.ver', 'clientes.gestionar', 'cuentas_cobrar.gestionar', 'pedidos.gestionar',
            'gastos.ver', 'gastos.registrar',
            'manuales.ver',
        ],
    ],

    /*
    | Explicación larga de cada permiso: qué deja hacer, dónde (web o tablet) y qué pasa
    | sin él. Se muestra con el botón «?» en la matriz de Roles y permisos.
    */
    'ayuda' => [
        'dashboard.ver' => 'Entra al panel de inicio: ventas del día, ticket promedio, lo más vendido, ventas por hora y avisos (productos bajo el mínimo, pedidos especiales para hoy o atrasados, turnos abiertos). Los totales de utilidad, gastos, compras y cartera solo se ven si además tiene «Ver reportes».',

        'pos.vender' => 'Es el permiso básico para atender: venta rápida, pedidos de mostrador, abrir mesas, agregar productos, enviar comandas a cocina o barra y cobrar (efectivo, tarjeta, transferencia o pago mixto). Sin él no entra al punto de venta ni a las mesas. Para vender también necesita un turno de caja abierto a su nombre, o el permiso «Vender y cobrar en el turno que abrió otra persona».',
        'pos.descuento' => 'Aplica descuentos a un producto o a toda la venta, hasta el máximo sin autorización que está en Datos del negocio (por ejemplo, 10 %). Si el descuento pasa de ese máximo, se pide el PIN de alguien con «Autorizar descuentos mayores al máximo». Sin este permiso no puede poner ningún descuento.',
        'pos.descuento.autorizar' => 'Puede dar descuentos de cualquier tamaño sin pedir PIN, y su PIN sirve para autorizar el descuento grande que intenta otra persona en la tablet o la web. Cada descuento autorizado queda en Auditoría con quién lo autorizó.',
        'pos.anular' => 'Anular ventas ya cobradas (el producto regresa al inventario y el dinero sale del turno). El mismo permiso se usa para: cancelar un producto que ya se envió a cocina (sale el aviso CANCELADO), anular una comanda completa, liberar una mesa con productos sin cobrar, y anular abonos de clientes y gastos. Quien no lo tiene puede hacerlo si alguien con el permiso escribe su PIN. Todo queda en Auditoría con el motivo.',
        'pos.devolver' => 'Registra devoluciones parciales o totales de una venta: elige los productos, si regresan al inventario o van como dañados, y devuelve el dinero por el método que se indique. Sin el permiso se pide el PIN de quien lo tenga. Queda en Auditoría.',
        'pos.historial' => 'Ve el historial de ventas (Ventas): buscar por número, fecha, cliente o empleado, ver el detalle de cada ticket y reimprimirlo. Desde ahí también se anula o se devuelve si tiene esos permisos.',
        'pos.precuentas' => 'Imprime la precuenta de una mesa (lo que se debe, antes de cobrar), maneja los pedidos pendientes o en espera, reimprime comandas y es el permiso con el que la tablet del local toma la cola de impresión cuando el servidor está en la nube.',
        'pos.ver_existencias' => 'Muestra en cada tarjeta de producto, en la venta rápida y en las mesas (web y tablet), cuánto queda en existencia. Sin el permiso la cantidad no se muestra, pero «Agotado» se sigue viendo, porque es necesario para vender.',

        'mesas.configurar' => 'Configura el salón: crear, ordenar y desactivar mesas y terminales por zona, las áreas de preparación (Cocina, Barra) con su impresora, qué categoría va a qué área, y los modificadores o extras (Leche, Endulzante) con su precio y el insumo que descuentan.',

        'caja.abrir' => 'Abre su turno de caja con el fondo inicial. Cada empleado vende en su propio turno. Al abrir se ve la lista de compras que dejó el turno anterior.',
        'caja.cerrar' => 'Cierra su turno: cuenta el efectivo (por billetes y monedas o el total) y el sistema calcula el faltante o sobrante, imprime el cierre con las ventas por forma de pago y por empleado, y la lista de lo que hay que comprar. Al cerrar se cierra la sesión.',
        'caja.movimientos' => 'Registra ingresos y egresos de efectivo que no son ventas (por ejemplo, cambio que trae el dueño o dinero que se saca), con su concepto, y los anula con motivo. Afectan el efectivo esperado del cierre.',
        'caja.abrir_cajon' => 'Abre el cajón de dinero sin hacer una venta (por ejemplo, para cambiar un billete), desde Caja en la web o en la tablet. Siempre pide el motivo y queda en Auditoría quién lo abrió, quién autorizó y en qué turno. Sin el permiso se pide el PIN del administrador. Al cobrar en efectivo el cajón se abre solo; este permiso no afecta eso.',
        'caja.arqueo' => 'Hace arqueos parciales: contar el efectivo a media jornada para ver si cuadra, sin cerrar el turno. También imprime el Corte X.',
        'caja.historial' => 'Ve el historial de turnos cerrados de todos los empleados, con su detalle y diferencias, y los reimprime. Si en Datos del negocio está activo el «cierre ciego», solo quien tiene este permiso ve el efectivo esperado antes de contar; los demás cuentan sin saber cuánto debería haber.',
        'caja.turno_compartido' => 'Para varios meseros con su propia tablet y una sola caja: si otra persona ya abrió el turno, puede vender, cobrar mesas, recibir abonos y registrar gastos o movimientos en ese turno sin abrir el suyo. Todo el dinero cae al turno de quien lo abrió (y en su cierre y arqueo), pero cada venta guarda quién la cobró, y el cierre muestra las ventas por empleado. Sin el permiso, si el turno es de otro solo puede cerrarlo para abrir el propio.',
        'caja.reabrir' => 'Reabre un turno ya cerrado (por ejemplo, si se contó mal) con un motivo obligatorio que queda en Auditoría. No se puede si la caja ya tiene otro turno abierto.',

        'productos.ver' => 'Ve el catálogo de productos con su clase, precio, costo y existencia, y lo busca por nombre o código de barras. Solo consulta: no puede crear ni editar.',
        'productos.gestionar' => 'Crea y edita productos: nombre, clase (producto terminado, materia prima, reventa, empaque), si se vende, si lleva inventario, unidad, categorías, foto, color en el POS y stock mínimo. Al crear fija el precio inicial; para cambiarlo después necesita «Cambiar precios y costos».',
        'productos.precios' => 'Cambia el precio de venta y el costo de un producto que ya existe. Cada cambio queda en Auditoría con el precio de antes y el de después. Sin este permiso esos campos salen bloqueados en el formulario.',
        'catalogos.gestionar' => 'Crea y edita las categorías de productos y las unidades de medida (con su conversión, por ejemplo 1 lb = 453.6 g).',

        'inventario.ver' => 'Ve las existencias de todos los productos, las alertas de stock bajo y los movimientos de inventario. Recibe los avisos de stock bajo en la campana.',
        'inventario.registrar' => 'Registra entradas (lo que llega) y salidas (lo que se saca) de inventario con su motivo y costo. Las salidas de insumos de una receta activa piden además el PIN de quien tenga «Autorizar salidas y ajustes a la baja».',
        'inventario.ajustar' => 'Hace ajustes por conteo físico: se escribe lo que se contó y el sistema registra la diferencia (faltante o sobrante) valorada al costo. También cambia el stock mínimo. Los ajustes a la baja de insumos de receta piden el PIN de quien tenga «Autorizar salidas y ajustes a la baja».',
        'inventario.autorizar_baja' => 'Su PIN autoriza las salidas y ajustes a la baja de insumos que se usan en recetas activas (por ejemplo, el aceite, que solo debe bajar con los platos vendidos). Así nadie baja esos insumos a mano sin que quede quién lo autorizó. Queda en Auditoría y en el kárdex.',
        'inventario.kardex' => 'Ve el kárdex de un producto: cada entrada y salida con fecha, motivo, quién la hizo, el saldo y el costo, desde el saldo inicial hasta el final del periodo.',

        'proveedores.gestionar' => 'Crea y edita proveedores (NIT, NRC, contacto, días de crédito) y ve su estado de cuenta.',
        'compras.ver' => 'Ve las compras y órdenes de compra registradas, con su detalle y su PDF.',
        'compras.gestionar' => 'Crea órdenes de compra y registra las compras recibidas: suben el inventario y actualizan el costo promedio. Las compras a crédito generan la cuenta por pagar. También anula compras con motivo.',
        'cuentas_pagar.gestionar' => 'Registra pagos a proveedores (de caja o de otra cuenta) y notas de crédito, y los anula con motivo. Ve lo que se debe a cada proveedor y lo vencido.',

        'recetas.gestionar' => 'Crea y edita recetas: las de producción (4 lb de frijol rinden 80 porciones) y las de venta (una limonada lleva 4 limones), con su rendimiento y merma. Las recetas de venta son las que descuentan insumos al vender.',
        'produccion.ver' => 'Ve las producciones registradas, con lo planificado, lo producido, el rendimiento y el costo.',
        'produccion.registrar' => 'Registra la producción del día: suma el producto terminado al inventario y descuenta las materias primas de su receta. También anula una producción con motivo.',
        'produccion.ver_receta' => 'Ve los ingredientes, cantidades y procedimiento de cada receta: en Producción, en el detalle de una producción, en el resumen de consumo y en Recetas. Sin este permiso se puede registrar la producción igual (se descuenta lo de la receta), pero solo se ve qué se produce y cuánto, no con qué. Quien gestiona recetas siempre los ve.',
        'mermas.registrar' => 'Registra mermas (producto quemado, vencido, dañado) con su motivo: bajan el inventario y salen al costo en los reportes de mermas y de utilidad. También las anula.',

        'clientes.ver' => 'Ve la lista de clientes y su información, y los busca para asignarlos a una venta o a una mesa.',
        'clientes.gestionar' => 'Crea y edita clientes: nombre, documento, teléfono, dirección, y su límite y días de crédito. Sin límite de crédito el cliente no puede comprar «al crédito».',
        'cuentas_cobrar.gestionar' => 'Registra abonos a la cuenta de un cliente que compró a crédito y ve su estado de cuenta. Para anular un abono hace falta «Anular ventas» o el PIN de quien lo tenga.',
        'pedidos.gestionar' => 'Maneja los pedidos especiales (encargos grandes y catering): crearlos con fecha de entrega, recibir anticipos, entregarlos cobrando lo que falta o cancelarlos.',
        'clientes.credito_exceder' => 'Autoriza vender a crédito aunque el cliente pase de su límite. Sin el permiso, la venta que pasa del límite pide el PIN de quien lo tenga, y queda en Auditoría.',

        'gastos.ver' => 'Ve la lista de gastos registrados (gas, luz, salarios) con su categoría y origen.',
        'gastos.registrar' => 'Registra gastos, pagados desde la caja del turno (bajan el efectivo esperado) o desde otra cuenta. Para anular un gasto hace falta «Anular ventas» o el PIN de quien lo tenga.',
        'gastos.categorias' => 'Crea y edita las categorías de gasto (Gas, Luz, Salarios...).',

        'impresoras.gestionar' => 'Configura las impresoras térmicas: conexión (desde la tablet, red o USB), tipo (ESC/POS o Star), papel, qué imprime, cajón, y las pruebas de impresión. Ve las impresiones con error para reintentarlas o borrarlas.',

        'reportes.ver' => 'Entra a Reportes: ventas por día, hora, empleado y forma de pago, productos más vendidos, utilidad estimada, cierres de caja, compras, gastos, inventario valorizado, kárdex, producción, mermas y cuentas por cobrar y por pagar. Con este permiso el panel de inicio muestra también la utilidad y el resumen del mes.',
        'reportes.exportar' => 'Descarga los reportes en Excel y PDF. Cada descarga queda en Auditoría.',

        'usuarios.gestionar' => 'Crea, edita y desactiva usuarios, les asigna su rol y les quita el PIN. Un usuario desactivado ya no puede entrar y se cierran sus sesiones abiertas.',
        'roles.gestionar' => 'Cambia qué puede hacer cada rol (esta pantalla) y qué manuales ve cada rol. El cambio aplica de inmediato y queda en Auditoría. Es un permiso delicado: quien lo tiene puede darse cualquier otro permiso.',
        'auditoria.ver' => 'Ve el registro de Auditoría: inicios de sesión, anulaciones, devoluciones, descuentos autorizados, aperturas del cajón, ajustes de inventario, cambios de precio, cierres y reaperturas de caja, y cambios de usuarios y permisos, con quién, cuándo y desde dónde.',
        'configuracion.gestionar' => 'Edita los Datos del negocio: nombre, NIT, NRC, dirección y logo del ticket, mensaje del ticket, si se permite vender sin existencia (stock negativo), el descuento máximo sin autorización y el cierre ciego. También las cajas y los métodos de pago.',

        'manuales.ver' => 'Muestra la sección Manuales con las guías de uso. Qué manuales ve cada rol se elige en «Manuales por rol».',
    ],

];
