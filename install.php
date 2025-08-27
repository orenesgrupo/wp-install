<?php
function generate_random($length, $symbols = false) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ($symbols) $chars .= '.:,;-_@?!"$%&=[]{}*/<>';
	return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

function generate_username($length, $symbols = false) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.:,;-_@?!"$%&=';
	return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

function output($message) {
	echo "<p>$message</p>";
	@ob_flush();
	@flush();
	sleep(1);
}

function slugify($s) {
	$s = strtolower($s);
	$s = preg_replace('/[^a-z0-9\-]+/','-', $s);
	$s = preg_replace('/-+/','-', $s);
	return trim($s, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$db_name   = $_POST['db_name'];
	$db_user   = $_POST['db_user'];
	$db_pass   = $_POST['db_pass'];
	$db_host   = $_POST['db_host'];
	$prefix    = $_POST['prefix'];
	$email     = $_POST['email'];
	$username  = $_POST['username'];
	$password  = $_POST['password'];
	$child_in  = trim($_POST['child_theme'] ?? '');
	$child_slug = $child_in !== '' ? slugify($child_in) : '';

	echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalando...</title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head><body class='container py-4'>";
	echo "<h2 class='mb-4'>Instalador WordPress Grupo Orenes</h2>";

	output("Descargando WordPress...");
	file_put_contents("latest.zip", file_get_contents("https://wordpress.org/latest.zip"));
	$zip = new ZipArchive;
	if ($zip->open('latest.zip') === TRUE) {
		$zip->extractTo('.');
		$zip->close();
		unlink('latest.zip');
		output("WordPress descomprimido.");
	} else {
		die("No se pudo descomprimir WordPress.");
	}

	foreach (scandir('wordpress') as $file) {
		if (!in_array($file, ['.', '..'])) {
			rename("wordpress/$file", $file);
		}
	}
	rmdir('wordpress');

	output("Creando archivo wp-config.php...");
	$config = file_get_contents("wp-config-sample.php");
	$config = str_replace(
		['database_name_here', 'username_here', 'password_here', 'localhost', '$table_prefix  = \'wp_\';'],
		[$db_name, $db_user, $db_pass, $db_host, "\$table_prefix  = '{$prefix}';"],
		$config
	);
	file_put_contents("wp-config.php", $config);

	define('WP_INSTALLING', true);
	require_once __DIR__ . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	// MU-plugin para permitir símbolos en user_login
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
	if (!is_dir($mu_dir)) { @mkdir($mu_dir, 0755, true); }
	$mu_code = <<<'PHP'
<?php
add_filter('sanitize_user', function($username, $raw, $strict){
	return preg_replace('/[^A-Za-z0-9\.\:\,\;\-\_\=\@\#\$\%\&\¿\?\ñ\Ñ\!\¡\ç]/u', '', $raw);
}, 10, 3);
PHP;
	@file_put_contents($mu_dir.'/allow-symbolic-usernames.php', $mu_code);

	output("Instalando WordPress...");
	wp_install('Sitio Web', $username, $email, true, '', $password);

	global $wpdb;
	$user_id = (int) $wpdb->get_var(
		$wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email = %s ORDER BY ID ASC LIMIT 1", $email)
	);
	if ($user_id > 0) {
		$wpdb->update(
			$wpdb->users,
			[
				'user_login'   => $username,
				'user_nicename'=> 'admin',
				'display_name' => 'Samuel E. Cerezo',
			],
			['ID' => $user_id]
		);
	}

	output("Aplicando configuraciones básicas...");
	update_option('timezone_string', 'Europe/Madrid');
	update_option('uploads_use_yearmonth_folders', 0);
	update_option('permalink_structure', '/%postname%/');
	update_option('thumbnail_size_w', 100);
	update_option('thumbnail_size_h', 100);
	update_option('medium_size_w', 500);
	update_option('medium_size_h', 500);
	update_option('large_size_w', 1000);
	update_option('large_size_h', 1000);

	output("Instalando tema personalizado...");
	$theme_url = 'https://github.com/orenesgrupo/orenes/archive/refs/heads/main.zip';
	$theme_zip = 'orenes.zip';
	file_put_contents($theme_zip, file_get_contents($theme_url));
	$theme_dir = WP_CONTENT_DIR . '/themes/';
	unzip_file($theme_zip, $theme_dir);
	unlink($theme_zip);

	// Detectar slug real del tema padre orenes
	$folders = glob($theme_dir . 'orenes-*');
	$theme_slug = $folders ? basename($folders[0]) : 'orenes';
	switch_theme($theme_slug);
	foreach (wp_get_themes() as $slug => $theme) {
		if ($slug !== $theme_slug) delete_theme($slug);
	}

	// Crear tema hijo si procede
	if ($child_slug !== '') {
		output("Creando tema hijo: {$child_slug}...");
		$child_dir = $theme_dir . $child_slug;
		if (!is_dir($child_dir)) {
			@mkdir($child_dir, 0755, true);
		}

		// style.css mínimo para que WP reconozca el tema
		$style_css = "/*
Theme Name: {$child_slug}
Template: {$theme_slug}
*/";
		file_put_contents($child_dir . '/style.css', $style_css);

		// functions.php del hijo: encola CSS del padre y del hijo
		$functions_php = <<<'PHP'
<?php
add_action('wp_enqueue_scripts', function () {
	// CSS del padre
	wp_enqueue_style(
		'parent-style',
		get_template_directory_uri() . '/style.css',
		[],
		wp_get_theme(get_template())->get('Version')
	);
	// CSS del hijo (si lo usas)
	if (file_exists(get_stylesheet_directory() . '/style.css')) {
		wp_enqueue_style(
			'child-style',
			get_stylesheet_directory_uri() . '/style.css',
			['parent-style'],
			wp_get_theme()->get('Version')
		);
	}
}, 10);
PHP;
		file_put_contents($child_dir . '/functions.php', $functions_php);
	}

	output("Instalando plugins...");
	$plugins = [
		'elementor' => 'https://downloads.wordpress.org/plugin/elementor.latest-stable.zip',
		'complianz' => 'https://downloads.wordpress.org/plugin/complianz-gdpr.latest-stable.zip',
		'seo-by-rank-math' => 'https://downloads.wordpress.org/plugin/seo-by-rank-math.latest-stable.zip',
		'wp-cerber' => 'https://downloads.wpcerber.com/plugin/wp-cerber.zip',
	];
	include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$upgrader = new Plugin_Upgrader();
	foreach ($plugins as $slug => $url) {
		$upgrader->install($url);
		activate_plugin("$slug/$slug.php");
	}

	output("Configurando Elementor...");
	update_option('elementor_disable_color_schemes', 'yes');
	update_option('elementor_disable_typography_schemes', 'yes');
	update_option('elementor_load_fa4_shim', '');
	update_option('elementor_experiment-markup', 'active');
	update_option('elementor_page_title_selector', 'h1');
	update_option('elementor_active', 'yes');
	update_option('elementor_use_google_fonts', 'no');
	update_option('elementor_fonts_manager_font_display', 'swap');

	output("Ajustando página de inicio...");
	wp_update_post(['ID' => 2, 'post_title' => 'Inicio', 'post_name' => 'inicio']);
	update_option('show_on_front', 'page');
	update_option('page_on_front', 2);

	$posts = get_posts(['post_type' => 'post', 'numberposts' => -1]);
	foreach ($posts as $p) wp_delete_post($p->ID, true);

	output("<strong>Instalación finalizada correctamente.</strong>");
	output("Serás redirigido al panel de administración en 5 segundos...");

	echo '<meta http-equiv="refresh" content="5;url=' . site_url('/wp-admin') . '">';
	echo "<p class='text-muted small'>Este instalador se autodestruirá automáticamente.</p>";

	unlink(__FILE__);
	echo "</body></html>";
	exit;
}

function bsInput($name, $value, $label, $type='text') {
	return "
<div class='mb-3'>
<label class='form-label' for='$name'>$label</label>
<input type='$type' id='$name' name='$name' value=\"".htmlspecialchars($value, ENT_QUOTES)."\" required class='form-control'>
</div>";
}

$defaults = [
	'db_name'   => generate_random(20),
	'db_user'   => generate_random(20),
	'db_pass'   => generate_random(25, true),
	'db_host'   => '10.0.0.25',
	'prefix'    => generate_random(7) . '_',
	'email'     => 'samuel.cerezo@orenesgrupo.com',
	'username'  => generate_username(20, true),
	'password'  => generate_random(25, true),
	'child_theme' => '' // vacío por defecto
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

<div class='mb-3'>
  <label class='form-label' for='child_theme'>Carpeta tema hijo (opcional)</label>
  <input type='text' id='child_theme' name='child_theme' value="<?=htmlspecialchars($defaults['child_theme'], ENT_QUOTES)?>" class='form-control' placeholder='ej. orenes-child'>
  <div class="form-text">Se creará en <code>wp-content/themes/&lt;carpeta&gt;</code> como hijo de <code>orenes</code>.</div>
</div>

<button type="submit" class="btn btn-primary">Instalar</button>
</form>
</div>
</div>
</div>
</body>
</html>
