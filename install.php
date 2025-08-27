<?php
/* install-grupo-orenes.php
 * Instalador WordPress con Bootstrap.
 * - Email por defecto: samuel.cerezo@orenesgrupo.com
 * - Host BD por defecto: 10.0.0.25
 * - user_login admite símbolos tras instalación (actualización directa en DB)
 * - user_nicename = admin
 * - display_name  = "Samuel E. Cerezo"
 */

function generate_random(int $length, bool $symbols = false): string {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ($symbols) $chars .= '.:,;-_@?¿¡!"$%&=';
	return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

function generate_random_username(int $length = 20): string {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.:,;-_çñÑ¡!@#$%&=¿?';
	$out = '';
	$max = strlen($chars) - 1;
	for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, $max)];
	return $out;
}

function output_step(string $message): void {
	echo "<p>$message</p>";
	@ob_flush(); @flush();
	sleep(1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$db_name  = $_POST['db_name'];
	$db_user  = $_POST['db_user'];
	$db_pass  = $_POST['db_pass'];
	$db_host  = $_POST['db_host'];
	$prefix   = $_POST['prefix'];
	$email    = $_POST['email'];
	$username = $_POST['username']; // con símbolos
	$password = $_POST['password'];

	echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalando...</title>
	<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
	</head><body class='container py-4'>";
	echo "<h2 class='mb-4'>Instalador WordPress Grupo Orenes</h2>";

	// 1) Descargar y desplegar WordPress
	output_step("Descargando WordPress...");
	file_put_contents("latest.zip", file_get_contents("https://wordpress.org/latest.zip"));
	$zip = new ZipArchive;
	if ($zip->open('latest.zip') === TRUE) {
		$zip->extractTo('.');
		$zip->close();
		unlink('latest.zip');
		output_step("WordPress descomprimido.");
	} else {
		die("No se pudo descomprimir WordPress.");
	}

	foreach (scandir('wordpress') as $file) {
		if ($file === '.' || $file === '..') continue;
		rename("wordpress/$file", $file);
	}
	@rmdir('wordpress');

	// 2) Configuración básica
	output_step("Creando archivo wp-config.php...");
	$cfg = file_get_contents("wp-config-sample.php");
	$cfg = str_replace(
		['database_name_here', 'username_here', 'password_here', 'localhost', '$table_prefix  = \'wp_\';'],
		[$db_name, $db_user, $db_pass, $db_host, "\$table_prefix  = '{$prefix}';"],
		$cfg
	);
	file_put_contents("wp-config.php", $cfg);

	// 3) Cargar WordPress
	define('WP_INSTALLING', true);
	require_once __DIR__ . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// 4) MU-plugin para permitir símbolos en nombres de usuario en futuras operaciones y login
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
	if (!is_dir($mu_dir)) { @mkdir($mu_dir, 0755, true); }
	$mu_code = <<<'PHP'
<?php
/*
Plugin Name: Allow Symbolic Usernames
Description: Permite símbolos específicos en nombres de usuario y en el login.
*/
add_filter('sanitize_user', function($username, $raw, $strict){
	// Permitidos: . : , ; - _ ç ñ Ñ ¡ ! @ # $ % & = ¿ ?
	return preg_replace('/[^A-Za-z0-9\.\:\,\;\-\_\ç\ñ\Ñ\¡\!\@\#\$\%\&\=\¿\?]/u', '', $raw);
}, 10, 3);
PHP;
	@file_put_contents($mu_dir.'/allow-symbolic-usernames.php', $mu_code);

	// 5) Instalar WordPress con un login temporal seguro y luego sobreescribir en DB
	output_step("Instalando WordPress...");
	$provisional_login = 'temporal_' . substr(md5(uniqid('', true)), 0, 8);
	wp_install('Sitio Web', $provisional_login, $email, true, '', $password);

	// 6) Forzar user_login con símbolos, nicename y display_name
	global $wpdb;
	$user_id = (int) $wpdb->get_var(
		$wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email = %s ORDER BY ID ASC LIMIT 1", $email)
	);
	if ($user_id > 0) {
		$wpdb->update(
			$wpdb->users,
			[
				'user_login'   => $username,              // puede contener símbolos
				'user_nicename'=> 'admin',
				'display_name' => 'Samuel E. Cerezo',
			],
			['ID' => $user_id]
		);
	}

	// 7) Ajustes básicos
	output_step("Aplicando configuraciones básicas...");
	update_option('timezone_string', 'Europe/Madrid');
	update_option('uploads_use_yearmonth_folders', 0);
	update_option('permalink_structure', '/%postname%/');
	update_option('thumbnail_size_w', 100);
	update_option('thumbnail_size_h', 100);
	update_option('medium_size_w', 500);
	update_option('medium_size_h', 500);
	update_option('large_size_w', 1000);
	update_option('large_size_h', 1000);

	// 8) Tema
	output_step("Instalando tema personalizado...");
	$theme_url = 'https://github.com/orenesgrupo/orenes/archive/refs/heads/main.zip';
	$theme_zip = 'orenes.zip';
	file_put_contents($theme_zip, file_get_contents($theme_url));
	$theme_dir = WP_CONTENT_DIR . '/themes/';
	unzip_file($theme_zip, $theme_dir);
	@unlink($theme_zip);
	$folders = glob($theme_dir . 'orenes-*');
	if ($folders) {
		$theme_slug = basename($folders[0]);
		switch_theme($theme_slug);
		foreach (wp_get_themes() as $slug => $theme) {
			if ($slug !== $theme_slug) delete_theme($slug);
		}
	}

	// 9) Plugins gratuitos
	output_step("Instalando plugins...");
	$plugins = [
		'elementor'        => 'https://downloads.wordpress.org/plugin/elementor.latest-stable.zip',
		'complianz-gdpr'   => 'https://downloads.wordpress.org/plugin/complianz-gdpr.latest-stable.zip',
		'seo-by-rank-math' => 'https://downloads.wordpress.org/plugin/seo-by-rank-math.latest-stable.zip',
		'wp-cerber'        => 'https://downloads.wpcerber.com/plugin/wp-cerber.zip',
	];
	$upgrader = new Plugin_Upgrader();
	foreach ($plugins as $slug => $url) {
		$upgrader->install($url);
		activate_plugin("$slug/$slug.php");
	}

	// 10) Elementor ajustes
	output_step("Configurando Elementor...");
	update_option('elementor_disable_color_schemes', 'yes');
	update_option('elementor_disable_typography_schemes', 'yes');
	update_option('elementor_load_fa4_shim', '');
	update_option('elementor_experiment-markup', 'active');
	update_option('elementor_page_title_selector', 'h1');
	update_option('elementor_active', 'yes');
	update_option('elementor_use_google_fonts', 'no');
	update_option('elementor_fonts_manager_font_display', 'swap');

	// 11) Portada
	output_step("Ajustando página de inicio...");
	wp_update_post(['ID' => 2, 'post_title' => 'Inicio', 'post_name' => 'inicio']);
	update_option('show_on_front', 'page');
	update_option('page_on_front', 2);

	// 12) Limpieza entradas
	$posts = get_posts(['post_type' => 'post', 'numberposts' => -1]);
	foreach ($posts as $p) wp_delete_post($p->ID, true);

	// 13) Fin
	output_step("<strong>Instalación finalizada correctamente.</strong>");
	output_step("Serás redirigido al panel de administración en 5 segundos...");

	echo '<meta http-equiv="refresh" content="5;url=' . site_url('/wp-admin') . '">';
	echo "<p class='text-muted small'>Este instalador se autodestruirá automáticamente.</p>";

	@unlink(__FILE__);
	echo "</body></html>";
	exit;
}

// ---------- Formulario (Bootstrap) ----------
function bsInput(string $name, string $value, string $label, string $type='text'): string {
	return "
	<div class='mb-3'>
		<label class='form-label' for='$name'>$label</label>
		<input type='$type' id='$name' name='$name' value=\"".htmlspecialchars($value, ENT_QUOTES)."\" required class='form-control'>
	</div>";
}

$defaults = [
	'db_name'  => generate_random(20),
	'db_user'  => generate_random(20),
	'db_pass'  => generate_random(25, true),
	'db_host'  => '10.0.0.25',
	'prefix'   => generate_random(7) . '_',
	'email'    => 'samuel.cerezo@orenesgrupo.com',
	'username' => generate_random_username(20), // se aplicará tras la instalación
	'password' => generate_random(25, true),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Instalador WordPress Grupo Orenes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="card shadow-sm">
    <div class="card-body">
      <h2 class="card-title mb-4">Instalador WordPress Grupo Orenes</h2>
      <form method="POST">
        <?= bsInput('db_name',  $defaults['db_name'],  'Nombre de la base de datos') ?>
        <?= bsInput('db_user',  $defaults['db_user'],  'Usuario de la base de datos') ?>
        <?= bsInput('db_pass',  $defaults['db_pass'],  'Contraseña de la base de datos') ?>
        <?= bsInput('db_host',  $defaults['db_host'],  'Host de la base de datos') ?>
        <?= bsInput('prefix',   $defaults['prefix'],   'Prefijo de tablas') ?>
        <?= bsInput('email',    $defaults['email'],    'Email del administrador', 'email') ?>
        <?= bsInput('username', $defaults['username'], 'Usuario administrador (login)') ?>
        <?= bsInput('password', $defaults['password'], 'Contraseña del administrador') ?>
        <button type="submit" class="btn btn-primary">Instalar</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
