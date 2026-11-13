# Manuales de uso

Sección de ayuda al estilo de KOLO, en la web y en la tablet, con el mismo contenido.

## Contenido

- Vive en `resources/manuales/manuales.json`: módulos → manuales. Cada manual tiene `id`, `titulo`, `resumen`, `permisos` (los del módulo que explica), `donde` (`web`, `tablet`), `secciones` (`texto`, `pasos` o `puntos`), `ejemplos` (casos de uso con pasos), `preguntas` y `relacionados` (ids).
- Para cambiar o agregar un manual se edita ese archivo; no hace falta tocar la web ni la app. `ManualesTest::test_el_contenido_es_coherente` revisa que el JSON sea válido, que los ids no se repitan y que los permisos y relacionados existan.

## API

- `GET /manuales` (permiso `manuales.ver`, que tienen todos los roles base; el admin lo quita o lo da en Roles y permisos).

## Pantallas

- **Web** `/manuales` (menú «Manuales»): tarjetas por módulo, buscador que revisa todo el texto, filtro por módulo y «Solo lo que puedo usar». `/manuales/:id` muestra el manual con índice, pasos numerados, casos de uso, preguntas, relacionados, anterior/siguiente e «Imprimir».
- **Tablet** botón «Manuales» en Inicio (`app/manuales.tsx`): lo mismo; por defecto muestra solo los manuales que aplican a la tablet y que el rol puede usar.

## Qué manuales ve cada rol (2026-11-05)

- Cada manual tiene su permiso `manual.{id}`. `ManualService::sincronizarPermisos()` (lo llaman el seeder y las rutas de manuales) crea los de manuales nuevos y borra los de manuales que ya no están en el JSON. Un manual nuevo se da de entrada a los roles que usan su módulo (o a todos si no pide permiso).
- `GET /manuales` devuelve solo los manuales del rol del usuario; el admin ve todos.
- `GET /manuales/permisos` y `PUT /manuales/permisos/{rol} {manuales: [ids]}` (permiso `roles.gestionar`): pantalla **Usuarios y seguridad → Manuales por rol** (`/manuales/permisos`, también desde el botón «Qué manuales ve cada rol» en Manuales). Queda en auditoría como `roles/manuales`.
- La matriz de Roles y permisos no muestra los `manual.*` y, al guardarla, conserva los que el rol ya tenía. `manuales.ver` sigue siendo el interruptor general de la sección.

## Roles

El seeder crea solo `admin` y `mesero` (ya no `cajero`, `encargado_inventario`, `editor` ni los permisos `admin.sidebar.*` / `editor.sidebar.*`). Los roles `cajero`, `encargado_inventario` y `editor` existen solo en las pruebas (`TestCase::ROLES_DE_PRUEBA`).
