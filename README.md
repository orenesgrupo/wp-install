# Instalador WordPress · Grupo Orenes

Script PHP de **instalación desatendida** de WordPress para nuevos proyectos de Grupo Orenes.
Descarga WP en español, crea `wp-config.php`, ejecuta la instalación, aplica ajustes base, instala el **tema padre** `orenes`, genera y activa **tema hijo** opcional, limpia contenido y plugins por defecto, configura **Elementor** y **WP 2FA**, e instala **WP Cerber** para activarse en el primer inicio. Muestra progreso en una consola web y **se autodestruye** al finalizar.

---

## Características

- WordPress ES (`latest-es_ES.zip`) y SALTs desde api.wordpress.org
- `wp-config.php` con prefijo aleatorio y valores del formulario
- Ajustes base: zona horaria, formatos, estructura de enlaces, tamaños de imagen, portada “Inicio”
- Tema padre `orenes` desde GitHub + tema hijo opcional con `functions/wp-cerber.php` (activador)
- **Screenshot** del tema hijo a partir de archivo subido
- Eliminación de entradas y plugins por defecto (Hello Dolly, Akismet) y de **temas predeterminados**
- Plugins: Elementor, Complianz, Rank Math, WP 2FA (con política precargada), WP Cerber (solo instalado)
- Config de Elementor por `update_option()` incluyendo **container**, **breakpoints**, **marcado optimizado**, **FA4 shim off**, **Google Fonts locales**, etc.
- Log en tiempo real vía `install.php?tail=1&pos={n}`
- **Autolimpieza**: borra `install.php` y `tmp.log` tras terminar

---

## Requisitos

- PHP 8.0+ recomendado 8.1/8.2
- Extensiones: `zip` (o PclZip incluido), `curl` o `allow_url_fopen`, `mysqli`
- Opcional: `gd` para generar `screenshot.png` del tema hijo
- Permisos de escritura en raíz del sitio y `wp-content/`
- Acceso MySQL válido al host indicado

---

## Uso

1. Copia `install.php` en la **raíz web vacía** del nuevo sitio.
2. Abre en el navegador: `https://tu-dominio/install.php`.
3. Rellena el formulario: credenciales MySQL, prefijo, datos admin, nombre y slug del **tema hijo** (opcional) y **imagen** para el screenshot (opcional).
4. Pulsa **Instalar**. Observa la consola.
5. Al mensaje “**Instalación finalizada correctamente**” se prepara la **redirección a `/wp-admin/`**.
6. El script se **borra** automáticamente.

> Log directo: `install.php?tail=1&pos=0`

---

## Qué instala y configura

### Ajustes base
- `timezone_string = Europe/Madrid`
- `date_format = j F Y`, `time_format = H:i`
- `uploads_use_yearmonth_folders = 0`
- `permalink_structure = /%postname%/`
- Nombre del sitio = nombre del tema hijo
- Tamaños: 100×100, 500×500, 1000×1000

### Tema
- Descarga y despliegue de `orenes` (rama `main`)
- Borrado de **todos** los temas excepto `orenes`
- Activación del **tema padre** y creación/activación del **tema hijo** si se indicó
- `functions/wp-cerber.php`: activa **WP Cerber** al primer arranque y se **autoborra**

### Plugins
- **Elementor**: se instalan y fijan opciones clave:
  - `elementor_experiment-*` relevantes: `container`, `grid`, `e_font_icon_svg`, `e_optimized_*`, `additional_custom_breakpoints`, `mega-menu`, `nested-elements`, etc.
  - `elementor_load_fa4_shim = ''`
  - `elementor_google_font = 0` y fuentes locales activadas
  - `elementor_meta_generator_tag = 1`
- **Complianz**, **Rank Math**, **WP 2FA** + `wp_2fa_policy` precargada
- **WP Cerber**: se **instala**, no se activa. El activador del tema hijo lo hará en el primer init.

---

## Campos del formulario

- **Base de datos**: nombre, usuario, contraseña, host, prefijo
- **Sitio**: nombre, usuario admin, email, contraseña
- **Tema hijo**: nombre, slug y **screenshot** opcional (se genera `screenshot.png` centrando y ajustando a 1200×900 con fondo blanco; si GD no está, se copia tal cual)

Los valores por defecto se generan aleatoriamente en carga.

---

## Personalización rápida

Edita constantes al inicio del script:

```php
const WP_ZIP_URL_ES     = 'https://es.wordpress.org/latest-es_ES.zip';  // Cambia localización
const THEME_PARENT_ZIP  = 'https://github.com/orenesgrupo/orenes/archive/refs/heads/main.zip';
const THEME_PARENT_SLUG = 'orenes';
```

---

## Seguridad y buenas prácticas

- **Ejecuta en un docroot vacío** y bajo **HTTPS**.
- Protege `install.php` temporalmente con **HTTP Auth** o IP allowlist si el entorno es público.
- Asegúrate de que el usuario MySQL solo tiene permisos sobre la base de datos objetivo.
- Verifica que el hosting permite `curl` o `allow_url_fopen`.
- El script se autodeletea. Si falla la autolimpieza, **borra manualmente** `install.php` y `tmp.log`.

---

## Solución de problemas

- **MySQL**: “no existe o no hay permisos” → crea la DB o asigna privilegios antes de ejecutar.
- **ZipArchive no disponible**: el script usa `unzip_file` y fallback a **PclZip**. Habilita `zip` si es posible.
- **403 en log/polling**: se ejecuta el borrado de polling antes de seguridad. Si un WAF intercepta, revisa reglas temporales.
- **Permisos**: asegúrate de escritura en raíz y `wp-content/themes` y `wp-content/plugins`.
- **cURL/SSL**: si falla descarga por SSL, revisa CA bundle del sistema.

---

## Estructura generada del tema hijo

```
wp-content/themes/{child-slug}/
├── style.css
├── functions.php
└── functions/
    └── wp-cerber.php   # activador one-shot de WP Cerber
```

---

## Registro en vivo

- Endpoint: `install.php?tail=1&pos={offset}`
- Respuesta: JSON `{ pos, chunk }`
- El frontal hace polling cada ~1,2 s y para tras “Instalación finalizada correctamente”.

---

## Licencia

Este instalador se entrega “tal cual”. Úsalo bajo la licencia del repositorio. Revisa también las licencias de WordPress, temas y plugins instalados.

---

## Mantenimiento

- Cambios de opciones de Elementor o nuevas features: añadir claves en el array `$options`.
- Actualización de políticas WP 2FA: sustituir el valor serializado y `delete_option('wp_2fa_settings_hash')`.
- Cambios de tema padre: actualizar `THEME_PARENT_ZIP` o `THEME_PARENT_SLUG`.

---

## Créditos

- WordPress © WordPress.org
- Plugins: Elementor, Complianz, Rank Math, WP 2FA, WP Cerber
- Tema padre: `orenes` (Grupo Orenes)
