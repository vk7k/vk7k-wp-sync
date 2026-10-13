# uxcribe.cl Sync

Plugin de sincronización y clonación de sitios WordPress (Push / Pull) desarrollado por **Uxcribe.cl**.

---

## 🌟 Características

- **Sincronización Bidireccional (Push / Pull)**:
  - **Modo Maestro (Master)**: Envía (Push) todo al destino (staging, producción o local).
  - **Modo Esclavo (Slave)**: Trae (Pull) todo desde el origen maestro.
- **Autenticación Criptográfica Robusta**:
  - Firma HMAC-SHA256 con timestamp y nonces para evitar ataques de replay.
  - Generación de claves de enlace de 64 caracteres.
- **Motor de Base de Datos Inteligente**:
  - Exportación e importación en bloques (chunks) para evitar timeouts y límites de memoria.
  - **Search & Replace Serializado Recursivo**: Recalcula con precisión las longitudes de strings en arrays y objetos serializados PHP (`s:18:...`) y estructuras JSON (WooCommerce, WPForms, ACF, Elementor, opciones del sistema).
  - Soporte para tablas personalizadas de WooCommerce (`wp_wc_*`, `wp_woocommerce_*`, `wp_actionscheduler_*`).
  - Respaldo de seguridad / Rollback automático antes de inyectar cambios.
- **Sincronización Diferencial de Archivos**:
  - Indexa y compara los hashes de archivos entre ambos sitios.
  - Solo transfiere archivos nuevos o modificados en lotes ZIP comprimidos.
  - Sincroniza `wp-content/uploads`, plugins y temas.
- **Bypass Directo de IP (DNS / Cloudflare)**:
  - Permite configurar una IP de destino para resolver peticiones cURL directamente si el DNS local aún no se ha propagado o si está detrás de proxies inversos.
- **Menú Unificado Uxcribe.cl**:
  - Integrado bajo el menú corporativo de WordPress **Uxcribe.cl**, unificando la administración de plugins.
- **Panel Moderno y Registro en Tiempo Real**:
  - Barra de progreso porcentual, indicadores de etapa y consola de eventos en vivo.

---

## 🚀 Instalación y Uso

1. **Instalar el plugin en ambos sitios WordPress**:
   - Copiar la carpeta `vk7k-wp-sync` en `wp-content/plugins/`.
   - Activar el plugin en ambos sitios desde el panel de WordPress.
2. **Configurar Roles y Claves**:
   - En el sitio de origen (ej. Local):
     - Ir a **Uxcribe.cl > Sync**.
     - Seleccionar **Rol: Maestro**.
     - Copiar la **Clave Secreta Local**.
     - Pegar la **URL del WordPress Remoto** (ej. `https://remoto.ejemplo.com`).
     - Pegar la **Clave Secreta del WordPress Remoto**.
     - *(Opcional)* Ingresar la **IP Directa del Servidor** (ej. `91.98.172.110`).
   - En el sitio de destino (ej. Staging):
     - Ir a **Uxcribe.cl > Sync**.
     - Seleccionar **Rol: Esclavo**.
     - Pegar la clave del Maestro.
3. **Ejecutar la Sincronización**:
   - Hacer clic en **Probar Conexión** para validar el enlace HMAC.
   - Seleccionar los elementos a sincronizar (Base de Datos, Multimedia, Plugins, Temas).
   - Hacer clic en **PUSH: Enviar Todo al Destino** (o **PULL: Traer Todo desde Origen**).

---

## 🛠️ Endpoints REST API

Todos los endpoints se encuentran protegidos bajo el namespace `vk7k-sync/v1/`:

| Endpoint | Método | Descripción |
|---|---|---|
| `/auth/test` | POST | Verifica handshake HMAC y retorna información del sistema |
| `/db/tables` | POST | Obtiene listado de tablas, recuento de filas y tamaños |
| `/db/export-chunk` | POST | Exporta esquema y lote de registros con Search & Replace |
| `/db/import-chunk` | POST | Inyecta un lote de registros SQL en la base de datos |
| `/db/backup` | POST | Genera un conjunto de tablas de respaldo |
| `/db/restore` | POST | Restaura las tablas de respaldo |
| `/files/manifest` | POST | Retorna el mapa de archivos y hashes MD5 |
| `/files/upload-chunk`| POST | Recibe y extrae un paquete ZIP de archivos |
| `/files/download-chunk`| POST | Empaqueta y descarga un lote ZIP de archivos |
| `/finalize` | POST | Regenera reglas de reescritura, permalinks y purga caché |

---

## 📦 Sistema de Actualizaciones (GitHub Releases)

A partir de la versión **1.3.0**, VK7K WP Sync incorpora un canal formal de distribución mediante **GitHub Releases**:

1. **Actualizaciones Oficiales en WordPress**:
   - Cada sitio conectado comprueba periódicamente nuevas versiones en [github.com/vk7k/vk7k-wp-sync](https://github.com/vk7k/vk7k-wp-sync).
   - Cuando se publica una nueva release oficial (ej. `v1.3.0`), WordPress notifica en la lista de plugins la disponibilidad de la actualización con botón **"Actualizar ahora"** y visor de notas de versión.
   - Enlace directo **"Buscar actualizaciones"** en la fila del plugin para forzar la comprobación en cualquier momento.

2. **Despliegue Manual bajo demanda**:
   - El handshake de conexión (`auth/test`) ya no sobreescribe código de forma silenciosa ni agresiva entre instancias.
   - Si se detecta una discrepancia de versión entre el sitio local y el remoto, el panel notifica la diferencia y ofrece un botón explícito: **"Desplegar vX.X.X local al remoto"** para sincronizar código únicamente cuando el administrador lo autorice.

3. **Novedades en v1.3.3**:
   - **Corrección de Error Crítico en Search & Replace (`wp_options`)**: Detección segura de instancias `__PHP_Incomplete_Class` para evitar que objetos de plugins ausentes (como SDKs de Freemius) rompan la exportación de lotes de base de datos.
   - **Modo Mantenimiento Inteligente no bloqueante**: Bypass garantizado para peticiones locales de `admin-ajax.php`, administradores con sesión activa (`wp-admin`) y llamadas de CLI.
   - **Limpieza SFTP en Aprovisionamiento SSH**: Eliminación segura de archivos `.maintenance` residuales sin bloquear el sitio remoto durante el emparejamiento.
   - **Activación Automática de Plugin**: El MU-plugin de Safe-Mode registra y activa oficialmente el plugin en la base de datos tras la extracción del bundle.

4. **Novedades en v1.3.4**:
   - **Preset Optimizado «Tema y Archivos de Diseño»**: Sincronización diferencial ultra-rápida de archivos físicos del tema activo (PHP, CSS, plantillas HTML, assets y theme.json) con cero consultas/modificaciones a la Base de Datos.

### ⚡ CLI usage (run from the WordPress root)

```bash
# Test connection and get remote info
php -r 'require "wp-load.php"; var_export(VK7K_Sync_Runner::test_connection());'

# Deploy the current plugin bundle to the remote site (push mode)
php -r 'require "wp-load.php"; var_export(VK7K_Sync_Runner::deploy_plugin_to_remote_via_rest());'

# Export a chunk of a table (example for wp_options)
php -r '
    require "wp-load.php";
    $body = [
        "table" => "wp_options",
        "offset" => 0,
        "limit" => 100,
        "replacements" => ["https://www.example.com" => "http://example.test"]
    ];
    $res = VK7K_Sync_Runner::send_remote_request("db/export-chunk", $body);
    var_dump($res);
';
```

The commands must be executed from the WordPress installation directory (where `wp-load.php` resides). Refer to `includes/class-vk7k-sync-cli.php` for additional CLI entry‑points and their parameters.


5. **Novedades en v1.4.1**:
   - **Stream Binario Directo (`application/octet-stream`) en PUSH de Archivos**: Envío de paquetes ZIP en streaming binario sin conversión Base64, reduciendo a cero la sobrecarga de RAM y evitando el error HTTP 413 (*Request Entity Too Large*) provocado por las reglas de ModSecurity (`SecRequestBodyNoFilesLimit`) en servidores Apache/cPanel.
   - **Ajuste Dinámico de Lotes ZIP**: Límite de seguridad de tamaño de lote de archivos a 10 MB para máxima estabilidad y prevención de timeouts en peticiones HTTP REST.
