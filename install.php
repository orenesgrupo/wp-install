<?php
declare(strict_types=1);

/*
 * Instalador WordPress Grupo Orenes
 * 
 * Descarga e instala WordPress. Configura las opciones básicas. Instala tema padre
 * de Grupo Orenes, crea un tema hijo y lo activa. Elimina el contenido de prueba y
 * crea la página de inicio. Elimina plugins y temas por defecto de WordPress.
 * Instala y activa plugins Elementor, Complianz, Rank Math y WP Cerber. Muestra
 * progreso y errores en consola. Una vez instalado y configurado, elimina los
 * archivos de instalación.
 */

const LOG_FILE           = __DIR__.'/tmp.log';
const WP_ZIP_URL_ES      = 'https://es.wordpress.org/latest-es_ES.zip';
const THEME_PARENT_ZIP   = 'https://github.com/orenesgrupo/orenes/archive/refs/heads/main.zip';
const THEME_PARENT_SLUG  = 'orenes';


// Helpers

function output(string $msg): void {
	file_put_contents(LOG_FILE, '['.date('d/m/Y H:i:s')."] $msg\n", FILE_APPEND);
}

function fetch_bytes(string $url, int $timeout = 60): string {
	if (function_exists('curl_init')) {
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => 'WP-Installer'
		]);
		$data = curl_exec($curl);
		$error  = curl_error($curl);
		$code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);
		if ($data === false || $code < 200 || $code >= 300) {
			throw new RuntimeException("HTTP $code $error");
		}
		return (string)$data;
	}
	$context = stream_context_create(['http'=>['timeout'=>$timeout,'follow_location'=>1,'user_agent'=>'WP-Installer']]);
	$data = @file_get_contents($url, false, $context);
	if ($data === false) throw new RuntimeException('file_get_contents fail');
	return (string)$data;
}

function unzip(string $file, string $dir): void {
	if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive no disponible');
	$zip = new ZipArchive();
	$reader = $zip->open($file);
	if ($reader !== true) throw new RuntimeException('Zip open error '.$reader);
	if (!is_dir($dir)) mkdir($dir, 0775, true);
	if (!$zip->extractTo($dir)) { $zip->close(); throw new RuntimeException('extractTo fallo'); }
	$zip->close();
}

function delete_folder(string $dir): void {
	if (!is_dir($dir)) return;
	$iterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
	$files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
	@rmdir($dir);
}

function move_tree(string $source, string $destination): void {
	if (!is_dir($source)) return;
	foreach (scandir($source) ?: [] as $file) {
		if ($file === '.' || $file === '..') continue;
		@rename($source.'/'.$file, $destination.'/'.$file);
	}
}

function generate_random(int $length, bool $strong = false): string {
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ($strong) $alphabet .= '!@#$%^&*()-_=+[]{}';
	$string = '';
	$max = strlen($alphabet) - 1;
	for ($i=0; $i<$length; $i++) { $string .= $alphabet[random_int(0,$max)]; }
	return $string;
}

function generate_username(int $length): string {
	$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$string = '';
	$max = strlen($alphabet) - 1;
	for ($i=0; $i<$length; $i++) { $string .= $alphabet[random_int(0,$max)]; }
	return $string;
}

function slugify(string $string): string {
	$string = strtolower(trim($string));
	$string = iconv('UTF-8','ASCII//TRANSLIT',$string);
	$string = preg_replace('/[^a-z0-9]+/','-',$string);
	return trim($string,'-');
}

function generate_input($name,$value,$label,$type='text') {
	return "<div class='mb-3'><label class='form-label' for='$name'>".htmlspecialchars($label)."</label>"
		."<input type='$type' id='$name' name='$name' value=\"".htmlspecialchars((string)$value,ENT_QUOTES)."\" required class='form-control'></div>";
}

function save_screenshot(string $temp, string $dir): void {
	$file = rtrim($dir, '/').'/screenshot.png';
	if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
	$ok = false;
	if (function_exists('getimagesize') && function_exists('imagecreatetruecolor')) {
		$info = @getimagesize($temp);
		if (is_array($info)) {
			$source = @imagecreatefromstring((string)file_get_contents($temp));
			if ($source) {
				$width = imagesx($source); $height = imagesy($source);
				$max_width = 1200; $max_height = 900;
				$ratio = min($max_width/$width, $max_height/$height);
				$new_width = (int)floor($width*$ratio); $new_height = (int)floor($height*$ratio);
				$destination = imagecreatetruecolor($max_width, $max_height);
				$background = imagecolorallocate($destination, 255,255,255);
				imagefilledrectangle($destination, 0,0, $max_width,$max_height, $background);
				$x = (int)(($max_width-$new_width)/2); $y = (int)(($max_height-$new_height)/2);
				imagecopyresampled($destination, $source, $x, $y, 0, 0, $new_width, $new_height, $width, $height);
				$ok = imagepng($destination, $file);
				imagedestroy($destination); imagedestroy($source);
			}
		}
	}
	if (!$ok) { @copy($temp, $file); }
}

function install_activate(Plugin_Upgrader $upgrader, string $slug, string $zip, string $label): void {
	output("Instalando {$label}...");
	$result = $upgrader->install($zip);
	if (is_wp_error($result)) { output("ERROR {$label}: ".$result->get_error_message()); return; }
	$plugin = null;
	foreach (array_keys(get_plugins()) as $file) {
		if (strpos($file, "$slug/") === 0 || stripos($file, $slug) !== false) { $plugin = $file; break; }
	}
	if ($plugin) {
		$activation = activate_plugin($plugin);
		if (is_wp_error($activation)) output("WARN activar {$label}: ".$activation->get_error_message());
		else output("{$label} activado");
	} else {
		output("WARN: no se encontró el archivo del plugin {$slug}");
	}
}


// JSON del log

if (isset($_GET['tail'])) {
	header('Content-Type: application/json; charset=utf-8');
	$pos  = max(0, (int)($_GET['pos'] ?? 0));
	$size = is_file(LOG_FILE) ? (int)filesize(LOG_FILE) : 0;
	if ($pos > $size) $pos = 0;
	$chunk = '';
	if ($size > 0) {
		$fh = @fopen(LOG_FILE, 'rb');
		if ($fh) { fseek($fh, $pos); $chunk = (string)stream_get_contents($fh); fclose($fh); }
		else { $chunk = "[WARN] No se puede abrir el log\n"; }
	}
	echo json_encode(['pos'=>$pos + strlen($chunk), 'chunk'=>$chunk], JSON_UNESCAPED_UNICODE);
	exit;
}


// Formulario

if (!isset($_GET['run'])) {
	$defaults = [
		'db_name'   => generate_random(20),
		'db_user'   => generate_random(20),
		'db_pass'   => generate_random(25, true),
		'db_host'   => '10.0.0.25',
		'prefix'    => generate_random(7).'_',
		'email'     => 'samuel.cerezo@orenesgrupo.com',
		'username'  => generate_username(20),
		'password'  => generate_random(25, true),
		'child_name'=> '',
		'child_slug'=> '',
		'sitename'  => '',
	];
	?>
<!DOCTYPE html>
<html lang="es">
	<head>
		<meta charset="utf-8"><title>Instalador WordPress Grupo Orenes</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
		<style>
			#console-wrap{display:none}
			pre.console{background:#000;color:#eee;padding:16px;border-radius:6px;min-height:260px;white-space:pre-wrap;overflow:auto;font:12px/1.45 monospace}
		</style>
	</head>
	<body class="container py-5">
		<h2 class="mb-4">Instalador WordPress</h2>
		<div id="wrap-form">
			<form id="f" class="row" enctype="multipart/form-data">
				<div class="col-6"><?= generate_input('db_name',$defaults['db_name'],'Base de datos: Nombre') ?></div>
				<div class="col-6"><?= generate_input('db_user',$defaults['db_user'],'Base de datos: Usuario') ?></div>
				<div class="col-3"><?= generate_input('db_host',$defaults['db_host'],'Base de datos: Servidor') ?></div>
				<div class="col-3"><?= generate_input('prefix',$defaults['prefix'],'Base de datos: Prefijo') ?></div>
				<div class="col-6"><?= generate_input('db_pass',$defaults['db_pass'],'Base de datos: Contraseña') ?></div>
				<div class="col-6"><?= generate_input('sitename',$defaults['sitename'],'Nombre del sitio') ?></div>
				<div class="col-6"><?= generate_input('username',$defaults['username'],'Administrador: Usuario') ?></div>
				<div class="col-3"><?= generate_input('child_name',$defaults['child_name'],'Tema hijo: Nombre') ?></div>
				<div class="col-3"><?= generate_input('child_slug',$defaults['child_slug'],'Tema hijo: Carpeta') ?></div>
				<div class="col-6"><?= generate_input('email',$defaults['email'],'Administrador: Correo electrónico','email') ?></div>
				<div class="col-6">
					<div class="mb-3">
						<label class="form-label" for="child_screenshot">Tema hijo: Imagen</label>
						<input type="file" id="child_screenshot" name="child_screenshot" accept="image/*" class="form-control">
					</div>
				</div>
				<div class="col-6"><?= generate_input('password',$defaults['password'],'Administrador: Contraseña') ?></div>
				<div class="col-12"><button id="btn" class="btn btn-primary">Instalar</button></div>
			</form>
		</div>
		<div id="console-wrap" class="mt-4">
			<h5 id="console-title" class="mb-2">Consola</h5>
			<pre id="console" class="console">Entorno preparado</pre>
		</div>
		<script>
			const form = document.getElementById('f');
			const btn  = document.getElementById('btn');
			const con  = document.getElementById('console');
			const wrap = document.getElementById('wrap-form');
			const cw   = document.getElementById('console-wrap');
			let pos = 0, timer = null;
			function append(t) { con.textContent += t; con.scrollTop = con.scrollHeight; }
			async function poll() {
				try {
					const r   = await fetch('install.php?tail=1&pos='+pos+'&_='+Date.now(), {cache:'no-store'});
					const txt = await r.text();
					let j = null;
					try { j = JSON.parse(txt); }
					catch(e) {
						append("\n[JS] tail "+r.status+" "+txt.slice(0,60)+"\n");
						if (r.status === 403 || r.status === 404) { clearInterval(timer); }
						return;
					}
					if (j.chunk) {
						append(j.chunk);
						if (j.chunk.includes('Instalación finalizada correctamente')) {
							clearInterval(timer);
							const d = new Date();
							const pad = n => String(n).padStart(2, '0');
							const ts = `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
							const base = window.location.origin + window.location.pathname.replace(/install\.php.*/i, '');
							const target = base.replace(/\/+$/, '') + '/wp-admin/';
							append(`\n[${ts}] Redirigiendo al panel de administración en 5 segundos...\n`);
							setTimeout(() => { location.href = target; }, 5000);
						}
					}
					pos = j.pos;
				} catch(e) { append("\n[JS] "+e.message+"\n"); }
			}
			form.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				btn.disabled = true;
				wrap.style.display = 'none';
				cw.style.display = 'block';
				con.textContent = '';
				pos = 0;
				await fetch('install.php?tail=1&pos=0&_='+Date.now(), {cache:'no-store'});
				if (timer) clearInterval(timer);
				timer = setInterval(poll, 1200);
				const data = new FormData(form);
				fetch('install.php?run=1', {method:'POST', body:data}).catch(()=>{});
			});
		</script>
	</body>
</html>
<?php
	exit;
}


// Ejecutor

@unlink(LOG_FILE);
output('Comenzando la instalación');

set_error_handler(function($no,$str,$file,$line) { output("PHP:$no $str in $file:$line"); return false; });
set_exception_handler(function(Throwable $ex) { output('EXC '.get_class($ex).': '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine()); });
register_shutdown_function(function() {
	$e = error_get_last();
	if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
		output("FATAL {$e['message']} in {$e['file']}:{$e['line']}");
	}
});

/* Campos */
$db_name    = trim($_POST['db_name']   ?? '');
$db_user    = trim($_POST['db_user']   ?? '');
$db_host    = trim($_POST['db_host']   ?? '');
$prefix     = trim($_POST['prefix']    ?? '');
$db_pass    = (string)($_POST['db_pass'] ?? '');
$sitename   = trim($_POST['sitename']  ?? '');
$username   = trim($_POST['username']  ?? '');
$email      = trim($_POST['email']     ?? '');
$password   = (string)($_POST['password'] ?? '');
$child_name = trim($_POST['child_name']?? '');
$child_slug = trim($_POST['child_slug']?? '');


// Conexión a MySQL

output("Estableciendo conexión a base de datos...");
$mysqli = @mysqli_connect($db_host, $db_user, $db_pass);
if (!$mysqli) { output("ERROR MySQL: ".mysqli_connect_error()); exit; }
if ($db_name !== '' && !@mysqli_select_db($mysqli, $db_name)) {
	output("ERROR MySQL: la base de datos '{$db_name}' no existe o no hay permisos.");
	@mysqli_close($mysqli); exit;
}
@mysqli_close($mysqli);
output("Conexión a base de datos correcta");


// Comprobación inicial

if (file_exists(__DIR__.'/wp-config.php') || is_dir(__DIR__.'/wp-includes') || file_exists(__DIR__.'/wp-load.php')) {
	output("Ya existe una instalación WordPress en este servidor.");
	exit;
}


// Instalación WordPress

try {
	output("Descargando WordPress...");
	$wpZip = __DIR__.'/latest.zip';
	file_put_contents($wpZip, fetch_bytes(WP_ZIP_URL_ES));
	output("Descomprimiendo archivo de instalación...");
	unzip($wpZip, __DIR__);
	@unlink($wpZip);
	foreach (scandir('wordpress') as $file) {
		if ($file!=='.' && $file!=='..') { @rename("wordpress/$file", $file); }
	}
	@rmdir('wordpress');
	output("Configurando entorno...");
} catch (Throwable $e) {
	output("Error desplegando WordPress: ".$e->getMessage());
	exit;
}


// Actualizar las SALTS

try {
	output("Creando archivo wp-config.php...");
	$cfg = file_get_contents('wp-config-sample.php');
	if ($cfg === false) { throw new RuntimeException('No existe wp-config-sample.php'); }

	$cfg = str_replace(
		['database_name_here','username_here','password_here','localhost', '$table_prefix = \'wp_\';'],
		[$db_name, $db_user, $db_pass, $db_host, "\$table_prefix = '{$prefix}';"],
		$cfg
	);

	$cfg = preg_replace(
		"/^define\\(\\s*'(AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(KEY|SALT)'\\s*,.*?;\\s*\$/mi",
		"",
		$cfg
	);
	$salts = @file_get_contents('https://api.wordpress.org/secret-key/1.1/salt/');
	if (!$salts) { throw new RuntimeException('No se pudieron obtener las SALTS'); }
	$cfg = preg_replace(
		"/(\\/\\*\\s*That's all, stop editing!.*)/s",
		$salts."\n$1",
		$cfg,
		1
	);

	if (file_put_contents('wp-config.php', $cfg) === false) {
		throw new RuntimeException('No se pudo escribir wp-config.php');
	}
	output("wp-config.php creado");
} catch (Throwable $e) {
	output("Error creando wp-config.php: ".$e->getMessage());
	exit;
}


// Instalar WordPress

try {
	output("Instalando WordPress...");
	define('WP_INSTALLING', true);
	require_once __DIR__.'/wp-load.php';
	require_once ABSPATH.'wp-admin/includes/upgrade.php';
	require_once ABSPATH.'wp-admin/includes/schema.php';
	if (function_exists('wp_installing')) wp_installing(true);

	global $wpdb;
	$wpdb->suppress_errors(true);
	output("Creando tablas en base de datos...");
	make_db_current_silent();
	$wpdb->suppress_errors(false);

	if ($wpdb->last_error) { output("ERROR creando tablas: ".$wpdb->last_error); exit; }

	output("Ejecutando instalación...");
	$result = wp_install($sitename !== '' ? $sitename : 'Sitio Web', $username, $email, true, '', $password);
	if (is_wp_error($result)) { output("ERROR wp_install: ".$result->get_error_message()); exit; }
	$admin_id = (int)($result['user_id'] ?? 1);
	output("Instalación completada correctamente");
} catch (Throwable $e) {
	output("ERROR instalando WP: ".$e->getMessage());
	exit;
}


// Ajustes básicos

try {
	output("Aplicando configuraciones básicas...");
	update_option('timezone_string', 'Europe/Madrid');
	update_option('date_format', 'j F Y');
	update_option('time_format', 'H:i');
	update_option('uploads_use_yearmonth_folders', 0);
	update_option('permalink_structure', '/%postname%/');
	update_option('blogname', $child_name);
	update_option('blogdescription', 'Web desarrollada por Grupo Orenes');
	update_option('thumbnail_size_w', 100);
	update_option('thumbnail_size_h', 100);
	update_option('medium_size_w', 500);
	update_option('medium_size_h', 500);
	update_option('large_size_w', 1000);
	update_option('large_size_h', 1000);
	output("Configuración aplicada correctamente");
} catch (Throwable $e) { output("WARN ajustes: ".$e->getMessage()); }


// Tema padre e hijo

$child_dir_created = '';
try {
	output("Instalando tema Grupo Orenes...");
	$theme_dir  = WP_CONTENT_DIR.'/themes/';
	$target_dir = $theme_dir.THEME_PARENT_SLUG;
	if (!is_dir($theme_dir)) mkdir($theme_dir, 0775, true);

	$theme_zip = __DIR__.'/orenes.zip';
	file_put_contents($theme_zip, fetch_bytes(THEME_PARENT_ZIP));

	require_once ABSPATH.'wp-admin/includes/file.php';
	require_once ABSPATH.'wp-admin/includes/class-pclzip.php';
	WP_Filesystem();
	$unzipped = unzip_file($theme_zip, $theme_dir);
	if (is_wp_error($unzipped)) {
		output("unzip_file() falló: ".$unzipped->get_error_message()." -> probando ZipArchive");
		try { unzip($theme_zip, $theme_dir); }
		catch(Throwable $e) {
			$pcl = new PclZip($theme_zip);
			$res = $pcl->extract(PCLZIP_OPT_PATH, $theme_dir, PCLZIP_OPT_REPLACE_NEWER);
			if ($res == 0) { output("PclZip falló: ".$pcl->errorInfo(true)); throw new RuntimeException('Descompresión tema falló'); }
			else { output("Descomprimido con PclZip"); }
		}
	}
	@unlink($theme_zip);

	$matches = glob($theme_dir.'orenes-*', GLOB_ONLYDIR);
	$source_dir = $matches ? $matches[0] : (is_dir($target_dir) ? $target_dir : null);
	if (!$source_dir || !is_dir($source_dir)) { output("Error al descomprimir el tema Grupo Orenes."); exit; }

	if ($source_dir !== $target_dir) {
		if (is_dir($target_dir)) { delete_folder($target_dir); }
		if (!@rename($source_dir,$target_dir)) { output("Error al instalar el tema Grupo Orenes."); exit; }
	}

	output("Eliminando temas predeterminados...");

	foreach (wp_get_themes() as $slug => $t) { if ($slug !== THEME_PARENT_SLUG) delete_theme($slug); }
	switch_theme(THEME_PARENT_SLUG);
	output("Tema instalado correctamente");
	output("Creando tema hijo...");

	// tema hijo opcional
	$child_slug = $child_slug !== '' ? slugify($child_slug) : ($child_name !== '' ? slugify($child_name) : '');
	if ($child_slug !== '') {
		$child_dir = $theme_dir.$child_slug;
		if (!is_dir($child_dir)) {
			mkdir($child_dir, 0775, true);
			$style_css = "/*
Theme Name: {$child_name}
Template: ".THEME_PARENT_SLUG."
*/\n";
			file_put_contents($child_dir.'/style.css', $style_css);
			$functions_php = <<<'PHP'
<?php
add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('orenes-style', get_template_directory_uri().'/style.css', [], wp_get_theme(get_template())->get('Version'));
	if (file_exists(get_stylesheet_directory().'/style.css')) {
		wp_enqueue_style('child-style', get_stylesheet_directory_uri().'/style.css', ['orenes-style'], wp_get_theme()->get('Version'));
	}
}, 10);

// Cargar activador de WP Cerber si existe y luego se autodestruye.
add_action('init', function () {
	$f = get_stylesheet_directory().'/functions/wp-cerber.php';
	if (is_readable($f)) { include $f; }
}, 0);
PHP;
			file_put_contents($child_dir.'/functions.php', $functions_php);
			$child_dir_created = $child_dir;

			// Crear carpeta functions y el activador wp-cerber.php
			$func_dir = $child_dir.'/functions';
			if (!is_dir($func_dir)) { @mkdir($func_dir, 0775, true); }
			$cerber_activator = <<<'PHP'
<?php
defined('ABSPATH') || exit;

/**
 * Activa WP Cerber si está instalado y se autodestruye.
 * Ubicación: /wp-content/themes/{child}/functions/wp-cerber.php
 */
add_action('init', function() {
	static $done = false;
	if ($done) return;
	$done = true;

	if (!function_exists('get_plugins')) {
		$p = ABSPATH.'wp-admin/includes/plugin.php';
		if (file_exists($p)) require_once $p;
	}

	$plugin_key = '';
	if (function_exists('get_plugins')) {
		foreach (get_plugins() as $path => $meta) {
			$name = isset($meta['Name']) ? $meta['Name'] : '';
			if (stripos((string)$name, 'cerber') !== false) { $plugin_key = $path; break; }
		}
	}

	if ($plugin_key) {
		$active = (array) get_option('active_plugins', []);
		if (!in_array($plugin_key, $active, true)) {
			$active[] = $plugin_key;
			update_option('active_plugins', $active);
		}
		$plugin = WP_PLUGIN_DIR . '/' . $plugin_key;
		if (file_exists($plugin)) {
			@include_once $plugin;
			if (function_exists('do_action')) { do_action('activated_plugin', $plugin_key); }
		}
	}

	$me = __FILE__;
	register_shutdown_function(function() use ($me) {
		if (file_exists($me) && is_writable($me)) { @unlink($me); }
	});
}, 1);
PHP;
			file_put_contents($func_dir.'/wp-cerber.php', $cerber_activator);
		}
		switch_theme($child_slug);
		output("Tema hijo creado");
	}
} catch (Throwable $e) { output("WARN tema: ".$e->getMessage()); }

/* Screenshot del tema hijo si se subió */
if ($child_dir_created && isset($_FILES['child_screenshot']) && is_uploaded_file($_FILES['child_screenshot']['tmp_name'])) {
	try {
		output("Configurando tema hijo...");
		save_screenshot($_FILES['child_screenshot']['tmp_name'], $child_dir_created);
		output("Configuración completada correctamente");
		output("Activando tema hijo...");
		output($child_name." es ahora el tema activo");
	} catch (Throwable $e) { output("WARN screenshot: ".$e->getMessage()); }
}

/* Portada y limpieza contenido demo */
try {
	output("Eliminando contenido de muestra...");
	$posts = get_posts(['post_type' => 'post', 'numberposts' => -1, 'post_status'=>'any']);
	foreach ($posts as $p) wp_delete_post($p->ID, true);

	output("Configurando página de inicio...");
	$page = get_page_by_title('Página de ejemplo');
	if (!$page) {
		$by_slug = get_page_by_path('pagina-ejemplo', OBJECT, 'page');
		if ($by_slug instanceof WP_Post) { $page = $by_slug; }
	}
	if ($page instanceof WP_Post) {
		wp_update_post([
			'ID'         => $page->ID,
			'post_title' => 'Inicio',
			'post_name'  => 'inicio',
		]);
		update_option('show_on_front', 'page');
		update_option('page_on_front', $page->ID);
	} else {
		$new_id = wp_insert_post([
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Inicio',
			'post_name'   => 'inicio',
			'post_content'=> '',
		]);
		if (!is_wp_error($new_id) && $new_id) {
			update_option('show_on_front', 'page');
			update_option('page_on_front', (int)$new_id);
		} else {
		output("No se pudo configurar la página de inicio");
		}
	}
} catch (Throwable $e) { output("WARN contenido/portada: ".$e->getMessage()); }

try {
	output("Eliminando plugins predeterminados...");
	require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH.'wp-admin/includes/plugin.php';
	WP_Filesystem();

	foreach (['hello'=>'Hello Dolly','akismet'=>'Askimet'] as $slug => $name) {
		$file = WP_PLUGIN_DIR."/$slug.php";
		if (file_exists($file)) { unlink($file); output("Plugin $name eliminado correctamente"); }
		$file = WP_PLUGIN_DIR."/$slug/$slug.php";
		if (file_exists($file)) { deactivate_plugins("$slug/$slug.php", true); delete_folder(WP_PLUGIN_DIR."/$slug"); output("Plugin $name eliminado correctamente"); }
	}

	// cortar polling antes de activar seguridad
	output("Plugins eliminados correctamente");
} catch (Throwable $e) { output("WARN plugins: ".$e->getMessage()); }

/* Plugins al final. Cerber el último. */
try {
	output("Comenzando la instalación de plugins");
	require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH.'wp-admin/includes/plugin.php';
	WP_Filesystem();
	$upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());

	install_activate($upgrader, 'elementor', 'https://downloads.wordpress.org/plugin/elementor.latest-stable.zip', 'Elementor');

	output("Configurando Elementor...");

	$options = [
		'elementor_google_font' => 0,
		'elementor_experiment-additional_custom_breakpoints' => 'active',
		'elementor_experiment-cloud-library' => 'active',
		'elementor_experiment-container' => 'active',
		'elementor_experiment-container_grid' => 'active',
		'elementor_experiment-e_dom_optimization' => 'active',
		'elementor_experiment-e_element_cache' => 'inactive',
		'elementor_experiment-e_font_icon_svg' => 'active',
		'elementor_experiment-e_local_google_fonts' => 'active',
		'elementor_experiment-e_optimized_assets_loading' => 'active',
		'elementor_experiment-e_optimized_css_loading' => 'active',
		'elementor_experiment-e_optimized_markup' => 'active',
		'elementor_experiment-editor_v2' => 'active',
		'elementor_experiment-mega-menu' => 'active',
		'elementor_experiment-nested-elements' => 'active',
		'elementor_experiment-notes' => 'inactive',
		'elementor_experiment-taxonomy-filter' => 'active',
		'elementor_experiment-theme_builder_v2' => 'active',
		'elementor_font_display' => 'swap',
		'elementor_lazy_load_background_images' => 1,
		'elementor_load_fa4_shim' => '',
		'elementor_local_google_fonts' => 1,
		'elementor_meta_generator_tag' => 1
	];

	foreach ($options as $key => $value) {
		update_option($key, $value, false);
	}

	install_activate($upgrader, 'complianz-gdpr',   'https://downloads.wordpress.org/plugin/complianz-gdpr.latest-stable.zip',   'Complianz');
	install_activate($upgrader, 'seo-by-rank-math', 'https://downloads.wordpress.org/plugin/seo-by-rank-math.latest-stable.zip', 'Rank Math');
	install_activate($upgrader, 'wp-2fa',           'https://downloads.wordpress.org/plugin/wp-2fa.latest-stable.zip',           'WP 2FA');

	output("Configurando WP 2FA...");
	$valor_serializado = 'a:32:{s:12:"grace-policy";s:16:"use-grace-period";s:22:"enable_destroy_session";b:0;s:28:"2fa_settings_last_updated_by";s:1:"1";s:12:"limit_access";b:0;s:18:"hide_remove_button";b:0;s:25:"redirect-user-custom-page";b:0;s:32:"redirect-user-custom-page-global";b:0;s:20:"superadmins-role-add";s:2:"no";s:24:"superadmins-role-exclude";b:0;s:27:"separate-multisite-page-url";s:0:"";s:20:"backup_codes_enabled";b:0;s:12:"enable_email";b:0;s:18:"specify-email_hotp";b:0;s:11:"enable_totp";s:11:"enable_totp";s:30:"grace-policy-notification-show";s:24:"after-login-notification";s:17:"re-login-2fa-show";b:0;s:32:"grace-policy-after-expire-action";s:20:"configure-right-away";s:14:"included_sites";a:0:{}s:14:"enforced_roles";a:0:{}s:14:"enforced_users";a:0:{}s:14:"excluded_users";a:0:{}s:14:"excluded_roles";a:0:{}s:14:"excluded_sites";a:0:{}s:12:"grace-period";i:3;s:24:"grace-period-denominator";s:4:"days";s:23:"create-custom-user-page";s:2:"no";s:20:"custom-user-page-url";s:0:"";s:19:"custom-user-page-id";s:0:"";s:22:"hide_page_generated_by";s:0:"";s:24:"grace-period-expiry-time";s:10:"1760170839";s:18:"enforcement-policy";s:9:"all-users";s:13:"methods_order";a:2:{i:0;s:4:"totp";i:1;s:5:"email";}}';
    update_option('wp_2fa_policy', unserialize($valor_serializado), false);
    delete_option('wp_2fa_settings_hash');

	output("Plugins instalados y configuradoscorrectamente");
	// cortar polling antes de activar seguridad
	output("Aplicando medidas de seguridad...");

	// Instalar WP Cerber sin activarlo. Se activará en el primer inicio por el activador del tema hijo.
	$upgrader->install('https://downloads.wpcerber.com/plugin/wp-cerber.zip');

} catch (Throwable $e) { output("WARN plugins: ".$e->getMessage()); }

/* Fin y autolimpieza con margen */
output("Instalación finalizada correctamente");
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
@sleep(5);
@unlink(__FILE__);
@unlink(LOG_FILE);

