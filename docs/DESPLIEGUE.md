# Puesta en producción — servidor en la nube (VPS de Hostinger)

Decisión del 2026-11-06: el servidor (Laravel + MySQL) va en un VPS de Hostinger. La web y la API se publican en **un solo dominio con HTTPS** (`https://pos.tudominio.com` para la web y `https://pos.tudominio.com/api` para la API). Las impresoras las maneja **la tablet del local** (ver el paso 7).

Cambia `pos.tudominio.com` por el dominio real en toda la guía.

## 0. Lo que ya quedó hecho en el equipo de desarrollo

- **Respaldos** en `storage/app/respaldos/`:
  - `antes_de_produccion_2026-11-06_1350.sql.gz`: la base completa, con los datos de prueba.
  - `antes_de_limpiar_*.sql.gz`: el mismo estado, justo antes de limpiar.
  - `catalogo_limpio_datos_2026-11-06.sql`: solo los datos que quedaron después de limpiar.
- **`frozenbar` limpia** (`php artisan pos:limpiar-movimientos`):
  - Se borró: ventas, turnos, mesas, compras, gastos, producción, kárdex, notificaciones y auditoría.
  - Se conservó: productos, recetas, categorías, mesas, extras, la impresora, los usuarios, clientes y proveedores.
  - Quedó en cero: existencias, saldos y correlativos (las existencias conservan sus mínimos).
- **Comandos nuevos:**

| Comando | Para qué |
|---|---|
| `php artisan pos:respaldo [--nombre=x] [--dias=30]` | Respaldo comprimido de la base. Se programa solo a las 23:30 y guarda 30 días |
| `php artisan pos:limpiar-movimientos` | Deja la base lista para operar; saca un respaldo antes de borrar |
| `php artisan pos:revisar-produccion` | Lista de revisión: configuración, contraseñas de prueba, impresoras, respaldos |

## 1. Dominio

En el panel del dominio crea un registro **A** `pos` que apunte a la IP del VPS. Puede tardar unos minutos en propagarse.

## 2. Preparar el VPS (Ubuntu 24.04)

Entra por SSH (`ssh root@IP-del-VPS`) y ejecuta:

```bash
apt update && apt upgrade -y
apt install -y nginx mysql-server unzip git certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
timedatectl set-timezone America/El_Salvador
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable
```

Laravel 13 necesita PHP 8.3 o mayor. Si la imagen del VPS trae PHP 8.4, cambia `8.3` por `8.4` en los comandos y en la configuración de nginx.

### Base de datos

Corre `mysql_secure_installation` y después:

```bash
mysql -e "CREATE DATABASE frozenbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos'@'localhost' IDENTIFIED BY 'CAMBIA-ESTA-CLAVE';
GRANT ALL PRIVILEGES ON frozenbar.* TO 'pos'@'localhost'; FLUSH PRIVILEGES;"
```

## 3. API (Laravel)

```bash
mkdir -p /var/www && cd /var/www
git clone https://github.com/tatanstudios/apifrozenbar.git    # repo privado: usa un token o una deploy key
cd apifrozenbar
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
nano .env                       # APP_URL, DB_PASSWORD, CORS_ALLOWED_ORIGINS con el dominio real
php artisan key:generate
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
```

**Datos.** Hay dos caminos:

- **Recomendado: subir la base ya limpia** (conserva el catálogo). En el equipo de desarrollo saca un respaldo con `php artisan pos:respaldo --nombre=para_vps` y súbelo con `scp storage/app/respaldos/para_vps_*.sql.gz root@IP:/root/`. Luego, en el VPS:

  ```bash
  gunzip -c /root/para_vps_*.sql.gz | mysql -u pos -p frozenbar
  php artisan migrate --force     # no debe haber nada pendiente
  ```

- **Base vacía:** `php artisan migrate --force --seed` (después hay que cargar todo el catálogo).

**Imágenes de productos y logo:** copia `storage/app/public/` del equipo de desarrollo a la misma ruta del VPS, por ejemplo con `scp -r storage/app/public root@IP:/var/www/apifrozenbar/storage/app/`. Luego corre `chown -R www-data:www-data storage`.

**Cierre del paso:**

```bash
php artisan optimize
```

## 4. Web (React)

En el equipo de desarrollo:

```bash
cd reactfrozenbar
cp .env.production.example .env.production   # con el dominio real
npm run build
scp -r dist/* root@IP:/var/www/pos-web/
```

En el VPS crea la carpeta antes, con `mkdir -p /var/www/pos-web`.

## 5. nginx y HTTPS

`/etc/nginx/sites-available/pos`:

```nginx
server {
    listen 80;
    server_name pos.tudominio.com;
    client_max_body_size 10M;

    root /var/www/apifrozenbar/public;
    index index.php;

    # API de Laravel
    location /api {
        try_files $uri /index.php?$query_string;
    }

    # Imágenes de productos y logo
    location /storage/ {
        try_files $uri =404;
        expires 7d;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Web (React): todo lo demás
    location / {
        root /var/www/pos-web;
        try_files $uri /index.html;
    }

    location = /sw.js {
        root /var/www/pos-web;
        add_header Cache-Control "no-cache";
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/pos /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
certbot --nginx -d pos.tudominio.com     # HTTPS gratis; se renueva solo
```

Con HTTPS, la web también activa su modo sin conexión (service worker), que en `http://` no funcionaba.

## 6. Tareas programadas (respaldo diario)

```bash
crontab -u www-data -e
# agregar:
* * * * * cd /var/www/apifrozenbar && php artisan schedule:run >> /dev/null 2>&1
```

- **Respaldo:** `pos:respaldo` corre a las 23:30 y guarda 30 días en `storage/app/respaldos/`.
- **Copia fuera del VPS:** activa también los **respaldos o snapshots semanales del VPS** en el panel de Hostinger. De vez en cuando descarga un `.sql.gz` a otra computadora o a Drive.

## 7. Impresión desde la tablet

El servidor en la nube no llega a la red del local (`192.168.1.69`). Por eso:

1. **Cambiar la impresora.** En Configuración → Impresoras (web o tablet), la Star queda así:
   - Conexión: **«Desde la tablet (Wi-Fi)»**;
   - IP `192.168.1.69`, puerto `9100`;
   - tipo **Star TSP100 (imagen)**.
2. **Instalar el APK** en la tablet. Expo Go no sirve, porque la impresión usa un módulo nativo propio (`appfrozenbar/modules/impresora-tcp`):

   ```bash
   cd appfrozenbar
   npx eas-cli@latest login
   npx eas-cli@latest build -p android --profile preview
   ```

   EAS entrega un enlace para descargar el `.apk` e instalarlo en la tablet. Hay que permitir «instalar apps desconocidas».
3. **Configurar la tablet:**
   - En la pantalla del servidor escribe `https://pos.tudominio.com`.
   - No hay que activar nada en la tablet: todas las que estén abiertas con sesión imprimen.
4. **Probar** con «Imprimir prueba» y «Prueba producción»: deben salir en 3 segundos o menos.

**Cómo funciona:**

- **En el servidor:** cada ticket, comanda, aviso CANCELADO, cierre o apertura del cajón queda en `trabajos_impresion` como `pendiente`, con los bytes ya armados. Las Star reciben la imagen ya convertida.
- **En la tablet:** cada tablet con sesión revisa la cola cada 3 segundos y también justo después de cada venta o envío. La primera que toma un trabajo abre la conexión al puerto 9100, manda los bytes y le avisa al servidor si salió.
- **Si falla:** la tablet muestra un aviso y el trabajo queda con error. Se reintenta en Ajustes → Impresoras → «Impresiones con error» (tablet) o en Configuración → Impresiones (web), o se reimprime desde el ticket o la comanda.
- **Lo viejo no sale solo:** lo que lleva más de 30 minutos en cola se reintenta a mano, para no imprimir comandas que ya no sirven.
- **Dos tablets no imprimen lo mismo:** cada trabajo queda apartado 45 segundos para quien lo tomó.
- **Desde la web (computadora o celular):** se sigue usando el respaldo de impresión del navegador.

## 8. Antes de abrir

```bash
php artisan pos:revisar-produccion
```

Debe terminar en «Listo para operar». En particular:

- **Contraseñas:** cambia las de admin, mesero1 y mesero2 (hoy son de prueba) desde Usuarios.
- **PIN:** cada administrador pone el suyo desde Mi cuenta.
- **Existencias:** carga el conteo inicial desde Inventario → Registrar.
- **Datos del negocio:** NIT, NRC, dirección y mensaje del ticket, en Configuración → Datos del negocio.
- **Caja:** abre turno con el fondo real.

## 9. Actualizar el servidor después

```bash
cd /var/www/apifrozenbar
php artisan pos:respaldo --nombre=antes_de_actualizar
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

La web se actualiza con `npm run build` y subiendo `dist/` de nuevo. La tablet se actualiza con un APK nuevo; antes sube `versionCode` en `app.json`.

**Cambios de esquema:** en producción **no** se usa `migrate:fresh`, porque borraría todo. Desde que el sistema opera con datos reales, cada cambio de tabla debe ir en una migración nueva. La regla de editar la migración de creación solo valía mientras la base tenía datos de prueba.

## Sin internet en el local

Con el servidor en la nube, si se cae el internet del local:

- **La tablet** no puede vender, porque todo pasa por la API.
- **La web** sí tiene modo sin conexión (Fase 5): guarda las ventas en el navegador y las sincroniza al volver la conexión.

Conviene un plan B: datos móviles en la tablet o un router con respaldo 4G.
